<?php

namespace App\Http\Middleware;

use App\Models\McpInvocation;
use App\Services\Mcp\McpReadOnlyPreview;
use App\Services\Mcp\McpToolRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Also audit SDK-rejected tools/call envelopes; malformed protocol input never reaches a tool. */
final class AuditRejectedMcpCalls
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->input('method') === 'tools/call' && ! McpReadOnlyPreview::requested($request) && ! $request->attributes->get('mcp_tool_audited')) {
            $name = $request->input('params.name');
            $known = is_string($name) && isset(app(McpToolRegistry::class)->all()[$name]);
            McpInvocation::query()->create(['credential_id' => $request->attributes->get('mcp_credential')->id,
                'tool_name' => $known ? $name : null, 'target' => null, 'run_id' => null, 'result' => 'error',
                'error_code' => $known ? 'VALIDATION_ERROR' : 'NOT_FOUND', 'correlation_id' => (string) Str::uuid(), 'created_at' => now()]);
        }

        return $response;
    }
}
