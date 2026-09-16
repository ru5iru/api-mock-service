<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MockEndpoint;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

final class EndpointController extends Controller
{
    public function index(): View
    {
        return view('admin.endpoints.index');
    }

    public function create(): View
    {
        return view('admin.endpoints.form', ['endpoint' => null]);
    }

    public function edit(MockEndpoint $endpoint): View
    {
        return view('admin.endpoints.form', compact('endpoint'));
    }

    public function destroy(MockEndpoint $endpoint): RedirectResponse
    {
        $endpoint->delete();

        return redirect()->route('dashboard.endpoints.index')->with('status', 'Mock endpoint deleted.');
    }
}
