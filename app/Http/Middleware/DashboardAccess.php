<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class DashboardAccess
{
    public const SESSION_KEY = 'mockdeck.dashboard_authenticated';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('mock.dashboard_auth.enabled', true)) {
            return $next($request);
        }

        if (! $request->session()->get(self::SESSION_KEY, false)) {
            return redirect()->guest(route('dashboard.login'));
        }

        return $next($request);
    }
}
