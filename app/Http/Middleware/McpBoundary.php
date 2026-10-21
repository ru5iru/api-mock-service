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
        if (strlen($request->getContent()) > 1048576) {
            return response()->json(['error' => ['code' => 'VALIDATION_ERROR', 'message' => 'MCP input exceeds 1 MiB.']], 413);
        }
        $origin = $request->headers->get('Origin');
        if ($origin !== null && ! in_array(rtrim($origin, '/'), [rtrim(config('app.url'), '/'), ...config('mcp.allowed_origins', [])], true)) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Untrusted MCP Origin.']], 403);
        }

        return $next($request);
    }
}
