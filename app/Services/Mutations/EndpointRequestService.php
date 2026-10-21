<?php

namespace App\Services\Mutations;

use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Curl\HashVariant;
use App\Services\Curl\ParsedCurl;
use App\Services\Curl\RequestCredentialPolicy;
use App\Services\Matching\FieldMatchingConfiguration;
use App\Services\TestRuns\RunRouting;

final class EndpointRequestService
{
    public const EXAMPLE_CURL = <<<'CURL'
curl --request POST 'https://api.example.test/v1/items?limit=10' \
  --header 'Content-Type: application/json' \
  --header 'Authorization: Bearer replace-me' \
  --data '{"name":"Example"}'
CURL;

    /** @return array{parsed: ParsedCurl, variant: HashVariant, matching: array} */
    public function prepare(EndpointInput $input): array
    {
        $parsed = app(CurlParser::class)->parse($input->rawCurl);
        RunRouting::validateConfiguration($parsed);
        $matching = $this->fieldMatching($input, $parsed->url);
        $variant = app(CurlHasher::class)->forOptions($parsed,
            $input->excludeHeaders ? false : $input->excludeCookies,
            $input->excludeHeaders ? false : $input->excludeAuth,
            $input->excludeHeaders, $matching['excluded_query_params'],
            $matching['excluded_headers'], $matching['path_pattern_enabled']);

        return compact('parsed', 'variant', 'matching');
    }

    public function maskSecrets(string $curl): string
    {
        return app(RequestCredentialPolicy::class)->maskCurl($curl);
    }

    public function deriveName(ParsedCurl $parsed): string
    {
        $parts = parse_url($parsed->url) ?: [];
        $target = (string) ($parts['path'] ?? '/');

        return strtoupper($parsed->method).' '.$target;
    }

    public function fieldMatching(EndpointInput $input, ?string $url = null): array
    {
        return app(FieldMatchingConfiguration::class)->validate([
            'excluded_query_params' => $input->excludedQueryParams,
            'excluded_headers' => $input->excludedHeaderNames,
            'path_pattern_enabled' => $input->pathPatternEnabled,
        ], $url);
    }

    public function analyzeHeaders(EndpointInput $input, ParsedCurl $parsed, array $provenance = []): array
    {
        $transport = array_map('strtolower', config('mock.transport_header_names', []));
        $auth = array_map('strtolower', config('mock.auth_header_names', []));

        return array_map(function (array $header) use ($transport, $auth, $input, $provenance): array {
            $name = strtolower(trim($header['name']));
            $isSensitive = app(RequestCredentialPolicy::class)->sensitive($name, $header['value']);
            $reason = null;

            if (in_array($name, $transport, true)) {
                $reason = 'ignored: volatile transport header';
            } elseif ($input->excludeHeaders) {
                $reason = 'ignored: all headers excluded';
            } elseif ($input->excludeCookies && $name === 'cookie') {
                $reason = 'ignored: cookie policy';
            } elseif ($input->excludeAuth && in_array($name, $auth, true)) {
                $reason = 'ignored: authentication policy';
            }

            $coarseExcluded = $reason !== null;
            if ($reason === null && in_array($name, $input->excludedHeaderNames, true)) {
                $reason = 'ignored: individual exclusion';
            }

            return [
                'variable_tokens' => $this->variableTokens($provenance, 'header', $name),
                'normalized_name' => $name,
                'coarse_excluded' => $coarseExcluded,
                'name' => $header['name'],
                'value' => $header['value'],
                'display_value' => $isSensitive ? '••••••••' : $header['value'],
                'sensitive' => $isSensitive,
                'excluded_reason' => $reason,
            ];
        }, $parsed->headers);
    }

    public function queryParameters(EndpointInput $input, ParsedCurl $parsed, array $provenance = []): array
    {
        $query = (string) (parse_url($parsed->url, PHP_URL_QUERY) ?? '');
        if ($query === '') {
            return [];
        }

        return array_map(function (string $parameter) use ($input, $provenance): array {
            [$key, $value] = array_pad(explode('=', $parameter, 2), 2, '');
            $decodedKey = urldecode($key);
            $decodedValue = urldecode($value);
            $sensitive = app(RequestCredentialPolicy::class)->sensitive($decodedKey, $decodedValue, true);

            return [
                'variable_tokens' => $this->variableTokens($provenance, 'query', $decodedKey),
                'key' => $decodedKey,
                'excluded' => in_array($decodedKey, $input->excludedQueryParams, true),
                'value' => $decodedValue,
                'display_value' => $sensitive ? '••••••••' : $decodedValue,
                'sensitive' => $sensitive,
            ];
        }, explode('&', $query));
    }

    public function maskCanonical(string $canonical, array $headers): string
    {
        foreach ($headers as $header) {
            if ($header['sensitive']) {
                $canonical = (string) preg_replace(
                    '/^'.preg_quote(strtolower($header['name']), '/').':.*$/mi',
                    strtolower($header['name']).':••••••••',
                    $canonical,
                );
            }
        }

        foreach (config('mock.portable_config.sensitive_query_keys', []) as $key) {
            $canonical = (string) preg_replace(
                '/([?&]'.preg_quote(rawurlencode((string) $key), '/').'=)[^&\s]+/i',
                '$1REDACTED',
                $canonical,
            );
        }

        return $canonical;
    }

    public function prettyBody(string $body): string
    {
        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE
            ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $body;
    }

    public function curlWarnings(string $rawCurl): array
    {
        $warnings = [];
        $checks = [
            '/(?:^|\s)(?:-L|--location)(?:\s|$)/' => 'Redirect flags are ignored; only the pasted request URL is signed.',
            '/(?:^|\s)(?:-G|--get)(?:\s|$)/' => 'GET mode moves data fields into the query string before signing.',
            '/(?:^|\s)(?:-u|--user)(?:\s|$)/' => 'Basic-auth credentials are converted into an Authorization header.',
            '/(?:^|\s)(?:-b|--cookie)(?:\s|$)/' => 'Inline cookies are converted into a Cookie header.',
            '/(?:^|\s)(?:-F|--form)(?:\s|$)/' => 'Multipart forms are unsupported because generated boundaries are unstable.',
            '/--data-binary\s+@/' => 'File-backed request bodies are unsupported; paste the file contents inline.',
        ];

        foreach ($checks as $pattern => $message) {
            if (preg_match($pattern, $rawCurl) === 1) {
                $warnings[] = $message;
            }
        }

        if (preg_match_all('/(?:^|\R)\s*curl(?:\.exe)?\b/i', $rawCurl) > 1) {
            $warnings[] = 'Multiple curl commands were detected. Paste one request at a time.';
        }

        return $warnings;
    }

    private function variableTokens(array $provenance, string $type, string $name): array
    {
        return collect($provenance)->where('field_type', $type)->where('field_name', $name)->pluck('tokens')->flatten()->unique()->values()->all();
    }
}
