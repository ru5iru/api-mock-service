<x-layouts.dashboard title="Test-run history">
    <x-page-header title="Test-run history" :description="$run->label.' · '.$run->status" />
    <p class="field-help">Run configuration is read-only here. Agents update it through MCP with a configuration version. Deleted mocks retain their scoped history; run data is never exported.</p>
    @forelse ($entityIds as $entityId)
        <details class="normalized-details" data-disclosure>
            <summary><x-disclosure-chevron /> {{ $entityNames[$entityId] ?? 'Mock' }} · #{{ $entityId }}</summary>
            <livewire:admin.revision-history entity-type="endpoint" :entity-id="$entityId" :run-id="$run->id" :key="'run-history-'.$run->id.'-'.$entityId" />
        </details>
    @empty
        <div class="mini-empty-state"><strong>No run changes yet</strong><span>MCP writes add entries to this run’s history.</span></div>
    @endforelse
</x-layouts.dashboard>
