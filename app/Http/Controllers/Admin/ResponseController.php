<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MockResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Conventional response deletion endpoint retained alongside the Livewire UI
 * so non-Livewire/admin API extensions have a stable controller boundary.
 */
final class ResponseController extends Controller
{
    public function destroy(MockResponse $response): RedirectResponse
    {
        $endpoint = $response->mock_endpoint_id;
        $response->delete();

        return redirect()->route('dashboard.endpoints.edit', $endpoint)->with('status', 'Response deleted.');
    }
}
