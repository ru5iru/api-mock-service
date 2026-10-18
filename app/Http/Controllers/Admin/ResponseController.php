<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MockResponse;
use App\Services\Mutations\ResponseMutationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Conventional response deletion endpoint retained alongside the Livewire UI
 * so non-Livewire/admin API extensions have a stable controller boundary.
 */
final class ResponseController extends Controller
{
    public function destroy(MockResponse $response): RedirectResponse
    {
        $endpoint = $response->mock_endpoint_id;
        try {
            app(ResponseMutationService::class)->delete($endpoint, $response->id);
        } catch (ValidationException) {
            abort(422, 'Select and save another fallback before deleting this response.');
        }

        return redirect()->route('dashboard.endpoints.edit', $endpoint)->with('status', 'Response deleted.');
    }
}
