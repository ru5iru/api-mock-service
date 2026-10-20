<?php

namespace App\Services\Mcp;

use App\Models\McpUnmatchedRequest;
use App\Models\TestRun;
use App\Services\Curl\IncomingRequestFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Small encrypted ring independent of optional dashboard log-file retention. */
final class McpUnmatchedEvidence
{
    public function capture(Request $request, TestRun $run, string $id): void
    {
        $parsed = app(IncomingRequestFactory::class)->fromRequest($request);
        $complete = strlen($parsed->body) <= 65536 && count($parsed->headers) <= 64;
        $headers = array_slice($parsed->headers, 0, 64);
        foreach ($headers as &$header) {
            if (strlen($header['value']) > 4096) {
                $complete = false;
                $header['value'] = substr($header['value'], 0, 4096);
            }
        }
        unset($header);
        DB::transaction(function () use ($run, $id, $parsed, $headers, $complete): void {
            TestRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            McpUnmatchedRequest::query()->where('created_at', '<', now()->subDay())->delete();
            McpUnmatchedRequest::query()->create(['id' => (string) Str::uuid(), 'run_id' => $run->id,
                'request' => ['method' => $parsed->method, 'url' => $parsed->url, 'headers' => $headers, 'body' => substr($parsed->body, 0, 65536), 'request_id' => $id],
                'complete' => $complete, 'created_at' => now()]);
            $keep = McpUnmatchedRequest::query()->where('run_id', $run->id)->orderByDesc('sequence')->limit(20)->pluck('id');
            McpUnmatchedRequest::query()->where('run_id', $run->id)->whereNotIn('id', $keep)->delete();
        }, 3);
    }
}
