<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MockEndpoint;
use App\Models\Revision;
use App\Models\TestRun;
use Illuminate\Contracts\View\View;

final class TestRunHistoryController extends Controller
{
    public function __invoke(string $publicId): View
    {
        $run = TestRun::where('public_id', $publicId)->firstOrFail();
        $entityIds = Revision::where('test_run_id', $run->id)->where('entity_type', 'endpoint')->distinct()->pluck('entity_id');

        $entityNames = MockEndpoint::withoutGlobalScopes()->where('test_run_id', $run->id)->whereIn('id', $entityIds)->pluck('name', 'id');
        $retainedNames = Revision::where('test_run_id', $run->id)->where('entity_type', 'endpoint')
            ->latest('version_number')->get()->unique('entity_id')->mapWithKeys(fn ($revision) => [$revision->entity_id => $revision->snapshot['name'] ?? null]);
        $entityNames = $entityNames->union($retainedNames);

        return view('admin.run-history', compact('run', 'entityIds', 'entityNames'));
    }
}
