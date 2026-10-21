<?php

namespace App\Livewire\Admin;

use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Models\Revision;
use App\Models\TestRun;
use App\Services\Revisions\RevisionManager;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

final class RevisionHistory extends Component
{
    #[Locked]
    public string $entityType;

    #[Locked]
    public int $entityId;

    #[Locked]
    public ?int $runId = null;

    /** @var list<int> */
    public array $selectedRevisionIds = [];

    /** @var array<string, mixed> */
    public array $diff = [];

    public function mount(string $entityType, int $entityId, ?int $runId = null): void
    {
        abort_unless(in_array($entityType, ['endpoint', 'response'], true), 404);
        $this->entityType = $entityType;
        $this->entityId = $entityId;
        $this->runId = $runId;
        if ($runId === null) {
            $this->entity();
        } else {
            abort_unless($entityType === 'endpoint' && TestRun::find($runId) !== null, 404);
        }
    }

    public function toggleRevision(int $revisionId, RevisionManager $revisions): void
    {
        $revision = $this->revision($revisionId);
        if (in_array($revision->id, $this->selectedRevisionIds, true)) {
            $this->selectedRevisionIds = array_values(array_diff($this->selectedRevisionIds, [$revision->id]));
            $this->diff = [];

            return;
        }

        $this->selectedRevisionIds[] = $revision->id;
        $this->selectedRevisionIds = array_slice($this->selectedRevisionIds, -2);
        if (count($this->selectedRevisionIds) === 2) {
            $selected = Revision::query()->where('test_run_id', $this->runId)->where('entity_type', $this->entityType)->where('entity_id', $this->entityId)->whereKey($this->selectedRevisionIds)->orderBy('version_number')->get();
            abort_unless($selected->count() === 2, 404);
            $this->diff = $revisions->diff($selected->firstOrFail(), $selected->last());
        }
    }

    public function compareWithCurrent(int $revisionId, RevisionManager $revisions): void
    {
        $revision = $this->revision($revisionId);
        $this->selectedRevisionIds = [$revision->id];
        $this->diff = $revisions->diffCurrent($revision);
    }

    public function restore(int $revisionId, RevisionManager $revisions): void
    {
        abort_if($this->runId !== null, 403, 'Run history is read-only.');
        $revision = $this->revision($revisionId);
        $created = $revisions->restore($revision);
        $this->selectedRevisionIds = [];
        $this->diff = [];
        $this->dispatch('revision-restored', entityType: $this->entityType, entityId: $this->entityId);
        $this->dispatch('toast', message: 'Version '.$revision->version_number.' restored as version '.$created->version_number.'.');
    }

    public function clearDiff(): void
    {
        $this->selectedRevisionIds = [];
        $this->diff = [];
    }

    public function render(RevisionManager $revisions): View
    {
        $items = Revision::query()
            ->where('entity_type', $this->entityType)
            ->where('entity_id', $this->entityId)
            ->where('test_run_id', $this->runId)
            ->latest('version_number')
            ->get()
            ->each(fn (Revision $revision) => $revision->setAttribute('display_summary', $revisions->summary($revision)));

        return view('livewire.admin.revision-history', ['revisions' => $items, 'hasCurrent' => $this->runId === null || MockEndpoint::withoutGlobalScope('ordinary')->where('test_run_id', $this->runId)->whereKey($this->entityId)->exists()]);
    }

    private function revision(int $revisionId): Revision
    {
        return Revision::query()
            ->where('entity_type', $this->entityType)
            ->where('entity_id', $this->entityId)
            ->where('test_run_id', $this->runId)
            ->findOrFail($revisionId);
    }

    private function entity(): MockEndpoint|MockResponse
    {
        return $this->entityType === 'endpoint'
            ? MockEndpoint::query()->findOrFail($this->entityId)
            : MockResponse::query()->findOrFail($this->entityId);
    }
}
