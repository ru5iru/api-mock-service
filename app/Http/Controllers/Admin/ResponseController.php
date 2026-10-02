<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Services\Revisions\RevisionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Conventional response deletion endpoint retained alongside the Livewire UI
 * so non-Livewire/admin API extensions have a stable controller boundary.
 */
final class ResponseController extends Controller
{
    public function destroy(MockResponse $response): RedirectResponse
    {
        $endpoint = $response->mock_endpoint_id;
        DB::transaction(function () use ($response, $endpoint): void {
            $parent = MockEndpoint::query()->lockForUpdate()->findOrFail($endpoint);
            $current = $parent->responses()->findOrFail($response->id);
            abort_if($parent->selection_mode === 'rule' && $current->is_default, 422, 'Select and save another fallback before deleting this response.');
            $current->delete();
            if ($parent->selection_mode === 'sequence') {
                $revisions = app(RevisionManager::class);
                foreach ($parent->responses()->reorder()->orderBy('sequence_order')->orderBy('id')->get() as $index => $item) {
                    $before = $revisions->snapshot($item);
                    $item->update(['sequence_order' => $index]);
                    $revisions->recordIfChanged($item, $before);
                }
            }
        }, 3);

        return redirect()->route('dashboard.endpoints.edit', $endpoint)->with('status', 'Response deleted.');
    }
}
