<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deliberately permissive today. Replace this alias with auth middleware when
 * dashboard authentication is enabled; route/controller code stays unchanged.
 */
final class DashboardAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
