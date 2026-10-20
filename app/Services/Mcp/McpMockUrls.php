<?php

namespace App\Services\Mcp;

use App\Models\MockEndpoint;
use App\Models\TestRun;
use App\Services\Curl\CurlFormatter;
use App\Services\Curl\CurlParser;
use App\Services\Curl\ParsedCurl;
use App\Services\Matching\PathPattern;
use App\Services\TestRuns\RunRouting;

final class McpMockUrls
{
    public function forMock(TestRun $run, MockEndpoint $endpoint, string $preferred = 'header'): array
    {
        $parsed = app(CurlParser::class)->parse($endpoint->raw_curl);
        $parts = parse_url($parsed->url);
        $path = $parts['path'] ?? '/';
        if ($endpoint->path_pattern_enabled) {
            foreach (app(PathPattern::class)->samples($path) as $name => $value) {
                $path = str_replace('{'.$name.'}', rawurlencode($value), $path);
            }
        }
        $target = $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
        $origin = rtrim(config('app.url'), '/');
        $url = $origin.$target;
        $prefix = $origin.RunRouting::PREFIX.$run->public_id.$target;
        $formatter = app(CurlFormatter::class);

        return ['preferred' => $preferred, 'header' => ['url' => $url, 'headers' => [RunRouting::HEADER => $run->public_id],
            'curl' => $formatter->format(new ParsedCurl($parsed->method, $url, [...$parsed->headers, ['name' => RunRouting::HEADER, 'value' => $run->public_id]], $parsed->body))],
            'path_prefix' => ['url' => $prefix, 'curl' => $formatter->format(new ParsedCurl($parsed->method, $prefix, $parsed->headers, $parsed->body))],
            'origin_note' => 'URLs use APP_URL. A container or remote client must replace that origin with a reachable trusted origin; preserve the run metadata. Redacted credentials need caller-supplied values.'];
    }
}
