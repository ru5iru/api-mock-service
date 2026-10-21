<?php

namespace App\Http\Middleware;

use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpErrorCode;
use App\Services\Mcp\McpPermission;
use App\Services\Mcp\McpTemplatePreview;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Prepared for future routes; deliberately not attached to any production route in Phase 1. */
final class AuthenticateMcpCredential
{
    public function __construct(private readonly McpCredentialService $credentials) {}

    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        try {
            $credential = $this->credentials->authenticate($request->bearerToken(), recordUsage: ! McpTemplatePreview::requested($request));
            if ($permission !== null) {
                $required = McpPermission::tryFrom($permission);
                if ($required === null) {
                    throw new AuthorizationException('Unknown MCP permission.');
                }
                $this->credentials->authorize($credential, $required);
            }
        } catch (AuthenticationException $exception) {
            return response()->json(['error' => ['code' => McpErrorCode::AuthError->value, 'message' => $exception->getMessage()]], 401)
                ->header('WWW-Authenticate', 'Bearer')->header('Cache-Control', 'no-store');
        } catch (AuthorizationException $exception) {
            return response()->json(['error' => ['code' => McpErrorCode::Forbidden->value, 'message' => $exception->getMessage()]], 403)->header('Cache-Control', 'no-store');
        }
        $request->attributes->set('mcp_credential', $credential);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
