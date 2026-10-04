<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $token = is_string($bearer) && preg_match('/^mdv_[a-f0-9]{64}$/D', $bearer)
            ? ApiToken::query()->where('token_hash', hash('sha256', $bearer))->first()
            : null;
        if ($token === null) {
            return response()->json(['message' => 'A valid verification API bearer token is required.'], 401)
                ->header('WWW-Authenticate', 'Bearer')->header('Cache-Control', 'no-store');
        }
        // Session authentication and dashboard-auth-disabled never bypass this check.
        $token->forceFill(['last_used_at' => now()])->save();

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
