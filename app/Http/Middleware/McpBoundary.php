<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bound MCP payloads and reject cross-origin browser calls before protocol dispatch. */
final class McpBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        // Two bounded JSON text files may each require six bytes per source byte
        // when escaped in JSON-RPC. Keep the existing 1 MiB bound for other tools.
        $import = $request->input('method') === 'tools/call' && in_array($request->input('params.name'), ['preview_postman_import', 'apply_postman_import'], true);
        $limit = $import ? 12 * config('mock.portable_config.max_bytes', 2097152) + 65536 : 1048576;
        if (strlen($request->getContent()) > $limit) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'MCP input exceeds the transport limit.']], 413);
        }
        $origin = $request->headers->get('Origin');
        if ($origin !== null && ! in_array(rtrim($origin, '/'), [rtrim(config('app.url'), '/'), ...config('mcp.allowed_origins', [])], true)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Untrusted MCP Origin.']], 403);
        }

        return $next($request);
    }
}
