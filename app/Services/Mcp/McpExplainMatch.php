<?php

namespace App\Services\Mcp;

use App\Models\TestRun;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Curl\ParsedCurl;
use App\Services\Matching\EndpointMatcher;
use App\Services\Matching\PathPattern;
use App\Services\Mutations\EndpointRequestService;
use App\Services\TestRuns\RequestScope;

final class McpExplainMatch
{
    public function explain(TestRun $run, ParsedCurl $request): array
    {
        // This is the serving matcher itself, never the response selector.
        $selected = app(EndpointMatcher::class)->match($request, new RequestScope($run->environment, $run));
        $query = $run->endpoints()->orderByDesc('priority')->orderBy('path_pattern_enabled')->orderBy('id');
        $total = $query->count();
        $candidates = [];
        $window = $query->limit(50)->get();
        if ($selected !== null && ! $window->contains('id', $selected->endpoint->id)) {
            $window->pop();
            $window->prepend($selected->endpoint);
        }
        foreach ($window as $endpoint) {
            $actual = $request;
            $captures = [];
            $pathMatches = $endpoint->requestPath() === (parse_url($request->url, PHP_URL_PATH) ?: '/');
            if ($endpoint->path_pattern_enabled) {
                $captures = app(PathPattern::class)->capture($endpoint->requestPath(), parse_url($request->url, PHP_URL_PATH) ?: '/');
                $pathMatches = $captures !== null;
                if ($pathMatches) {
                    $actual = new ParsedCurl($request->method, app(PathPattern::class)->replaceUrlPath($request->url, $endpoint->requestPath()), $request->headers, $request->body);
                }
            }
            $canonical = app(CurlHasher::class)->forOptions($actual, $endpoint->exclude_cookies, $endpoint->exclude_auth, $endpoint->exclude_headers,
                $endpoint->excluded_query_params ?? [], $endpoint->excluded_headers ?? [], (bool) $endpoint->path_pattern_enabled)->normalized;
            [$head, $body] = array_pad(explode("\n\n", $canonical, 2), 2, '');
            [$storedHead, $storedBody] = array_pad(explode("\n\n", $endpoint->normalized_curl, 2), 2, '');
            $lines = explode("\n", $head);
            $stored = explode("\n", $storedHead);
            $differences = [
                ['field' => 'method', 'matches' => $endpoint->method === $request->method],
                ['field' => 'path', 'matches' => $pathMatches],
                ['field' => 'query', 'matches' => (explode('?', $lines[1] ?? '', 2)[1] ?? '') === (explode('?', $stored[1] ?? '', 2)[1] ?? '')],
                ['field' => 'included_headers', 'matches' => array_slice($lines, 2) === array_slice($stored, 2)],
                ['field' => 'body', 'matches' => hash_equals(hash('sha256', $body), hash('sha256', $storedBody))],
            ];
            $differences = [...$differences, ...$this->fieldDifferences($lines, $stored, $endpoint->excluded_query_params ?? [], $endpoint->excluded_headers ?? [])];
            $input = app(McpMockConfiguration::class)->endpoint([], $endpoint);
            $headers = app(EndpointRequestService::class)->analyzeHeaders($input, app(CurlParser::class)->parse($endpoint->raw_curl));
            $override = $endpoint->environmentOverrides()->whereKey($run->environment_id)->first();
            $candidates[] = ['mock_id' => $endpoint->uuid, 'name' => $endpoint->displayName(), 'priority' => $endpoint->priority,
                'selected' => $selected?->endpoint->id === $endpoint->id, 'enabled' => $endpoint->enabled && ($override?->pivot->enabled ?? true),
                'path_captures' => $captures ?? [], 'differences' => $differences,
                'excluded_query_params' => $endpoint->excluded_query_params ?? [],
                'headers' => array_map(fn ($header) => ['name' => $header['name'], 'excluded' => $header['excluded_reason'], 'sensitive' => $header['sensitive']], $headers)];
        }

        usort($candidates, function (array $left, array $right): int {
            $agreement = fn ($candidate) => collect($candidate['differences'])->whereIn('field', ['method', 'path'])->where('matches', true)->count();

            return (int) $right['selected'] <=> (int) $left['selected'] ?: $agreement($right) <=> $agreement($left) ?: $right['priority'] <=> $left['priority'];
        });

        return ['selected' => $selected?->endpoint->uuid, 'candidates' => $candidates, 'candidate_window' => ['limit' => 50, 'total' => $total, 'complete' => $total <= 50],
            'note' => 'Values are deliberately omitted from differences, including sensitive values. Exclusions are applied by the canonical builder before comparison. Higher priority and literal-path specificity determine the real selected endpoint.'];
    }

    private function fieldDifferences(array $actual, array $expected, array $excludedQuery, array $excludedHeaders): array
    {
        $query = function (array $lines): array {
            $fields = [];
            foreach (explode('&', explode('?', $lines[1] ?? '', 2)[1] ?? '') as $pair) {
                if ($pair === '') {
                    continue;
                }
                [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $fields[urldecode($name)][] = $value;
            }

            return $fields;
        };
        $headers = function (array $lines): array {
            $fields = [];
            foreach (array_slice($lines, 2) as $line) {
                [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
                if ($name !== '') {
                    $fields[strtolower($name)][] = $value;
                }
            }

            return $fields;
        };
        $result = [];
        foreach (['query' => [$query($actual), $query($expected), $excludedQuery], 'header' => [$headers($actual), $headers($expected), $excludedHeaders]] as $type => [$incoming, $configured, $excluded]) {
            $names = array_unique([...array_keys($incoming), ...array_keys($configured), ...$excluded]);
            sort($names);
            foreach ($names as $name) {
                $ignored = in_array($name, $excluded, true);
                $result[] = ['field' => $type, 'name' => $name, 'excluded' => $ignored, 'matches' => $ignored ? null : ($incoming[$name] ?? null) === ($configured[$name] ?? null)];
            }
        }

        return $result;
    }
}
