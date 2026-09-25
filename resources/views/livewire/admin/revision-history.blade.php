<div class="revision-history-panel">
    @if ($revisions->isEmpty())
        <div class="mini-empty-state revision-empty">
            <strong>No saved versions yet</strong>
            <span>A version is added only when a later save changes persisted fields.</span>
        </div>
    @else
        <div class="revision-timeline" aria-label="Version timeline">
            @php($currentDay = null)
            @foreach ($revisions as $revision)
                @php($day = $revision->created_at->toDateString())
                @if ($day !== $currentDay)
                    <div class="revision-day-separator"><span>{{ $revision->created_at->isToday() ? 'Today' : $revision->created_at->format('M j, Y') }}</span></div>
                    @php($currentDay = $day)
                @endif
                <article class="revision-row {{ in_array($revision->id, $selectedRevisionIds, true) ? 'selected' : '' }}" wire:key="revision-{{ $revision->id }}">
                    <span class="revision-marker" aria-hidden="true"></span>
                    <div class="revision-copy">
                        <div><strong>Version {{ $revision->version_number }}</strong><span class="action-chip {{ $revision->source === 'rollback' ? 'update' : 'create' }}">{{ str_replace('_', ' ', $revision->source) }}</span></div>
                        <p>{{ $revision->display_summary }}</p>
                        <small title="{{ $revision->created_at->toIso8601String() }}">{{ $revision->created_at->diffForHumans() }}</small>
                    </div>
                    <div class="revision-actions">
                        <button class="text-button" type="button" wire:click="toggleRevision({{ $revision->id }})">
                            {{ in_array($revision->id, $selectedRevisionIds, true) ? 'Unselect' : 'Select to compare' }}
                        </button>
                        <button class="text-button" type="button" wire:click="compareWithCurrent({{ $revision->id }})">Compare with current</button>
                        <button
                            class="text-button danger-text"
                            type="button"
                            wire:click="restore({{ $revision->id }})"
                            wire:confirm="Restore version {{ $revision->version_number }}? The current live values will change, and the restored state will be saved as a new rollback revision."
                        >Restore</button>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    @if ($diff !== [])
        <section class="revision-diff-viewer" aria-live="polite" aria-labelledby="revision-diff-heading">
            <div class="section-heading section-heading-inline">
                <div>
                    <h4 id="revision-diff-heading">Version {{ $diff['from']['version'] }} compared with {{ $diff['to']['kind'] === 'current' ? 'current' : 'version '.$diff['to']['version'] }}</h4>
                    <p>{{ count($diff['changes']) }} structural {{ Str::plural('change', count($diff['changes'])) }}</p>
                </div>
                <button class="text-button" type="button" wire:click="clearDiff">Close diff</button>
            </div>

            @forelse ($diff['changes'] as $change)
                <article class="revision-diff-change renderer-{{ $change['renderer'] }}">
                    <div class="revision-diff-heading">
                        <code>{{ $change['path'] }}</code>
                        <span class="action-chip {{ $change['type'] === 'removed' ? 'error' : ($change['type'] === 'added' ? 'create' : 'update') }}">{{ $change['type'] }}</span>
                    </div>
                    <div class="revision-diff-values">
                        <div>
                            <strong>Before</strong>
                            <pre>{{ is_array($change['before']) ? json_encode($change['before'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : ($change['before'] ?? '—') }}</pre>
                        </div>
                        <div>
                            <strong>After</strong>
                            <pre>{{ is_array($change['after']) ? json_encode($change['after'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : ($change['after'] ?? '—') }}</pre>
                        </div>
                    </div>
                </article>
            @empty
                <div class="info-note"><strong>No structural differences</strong><p>This version and the comparison target contain the same persisted values.</p></div>
            @endforelse
        </section>
    @elseif (count($selectedRevisionIds) === 1)
        <p class="revision-selection-note">Select one more version to compare, or use Compare with current.</p>
    @endif
</div>
