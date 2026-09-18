<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\DashboardAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class DashboardSessionController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (! config('mock.dashboard_auth.enabled', true)) {
            return redirect()->route('dashboard.endpoints.index');
        }

        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        if (! config('mock.dashboard_auth.enabled', true)) {
            return redirect()->route('dashboard.endpoints.index');
        }

        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:4096'],
        ]);

        $configuredUsername = (string) config('mock.dashboard_auth.username');
        $configuredPassword = (string) config('mock.dashboard_auth.password');

        abort_if(
            $configuredUsername === '' || $configuredPassword === '',
            503,
            'Dashboard authentication is not configured.',
        );

        $usernameMatches = hash_equals($configuredUsername, $credentials['username']);
        $passwordMatches = hash_equals($configuredPassword, $credentials['password']);

        if (! $usernameMatches || ! $passwordMatches) {
            return back()
                ->withErrors(['username' => 'The supplied dashboard credentials are invalid.'])
                ->onlyInput('username');
        }

        $request->session()->regenerate();
        $request->session()->put(DashboardAccess::SESSION_KEY, true);

        return redirect()->intended(route('dashboard.endpoints.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('dashboard.login');
    }
}
