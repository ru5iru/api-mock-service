@php
    $type = $row['type'] ?? 'string';
    $method = $row['method'] ?? 'person.firstName';
    $selectedMethod = collect($fakerCatalog)->firstWhere('id', $method);
    $groups = collect($fakerCatalog)->groupBy('module');
    $rowCount = is_array(data_get($builderSchema, $parentPath)) ? count(data_get($builderSchema, $parentPath)) : 1;
@endphp

<article
    class="schema-row"
    wire:key="schema-row-{{ str_replace('.', '-', $rowPath) }}"
    @if ($showKey)
        data-schema-row
        data-schema-parent="{{ $parentPath }}"
        data-schema-index="{{ $rowIndex }}"
    @endif
>
    <div class="schema-row-main">
        <span class="schema-drag-handle" @if ($showKey) draggable="true" data-schema-drag-handle title="Drag to reorder" @endif aria-hidden="true">⋮⋮</span>
        @if ($showKey)
            <label class="schema-key">
                <span class="sr-only">Field name</span>
                <input type="text" placeholder="Field name" wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.key">
            </label>
        @endif

        <label class="schema-type">
            <span class="sr-only">Field type</span>
            <select wire:model.live="builderSchema.{{ $rowPath }}.type">
                <option value="faker">Faker</option>
                <option value="string">String</option>
                <option value="number">Number</option>
                <option value="boolean">Boolean</option>
                <option value="date">Date</option>
                @if ($depth < 6)
                    <option value="object">Object</option>
                    <option value="array">Array</option>
                @endif
            </select>
        </label>

        <div class="schema-config">
            @if ($type === 'faker')
                <details class="faker-picker" data-faker-picker>
                    <summary title="{{ is_scalar($selectedMethod['sample'] ?? null) ? $selectedMethod['sample'] : json_encode($selectedMethod['sample'] ?? null) }}"><span>{{ $method }}</span><span aria-hidden="true">⌄</span></summary>
                    <div class="faker-picker-panel">
                        <label class="search-field faker-search"><span aria-hidden="true">⌕</span><input type="search" placeholder="Search methods" data-faker-search></label>
                        <div class="faker-options" data-faker-options role="listbox" aria-label="Faker methods">
                            @foreach ($groups as $module => $methods)
                                <section data-faker-group>
                                    <h4>{{ $module }}</h4>
                                    @foreach ($methods as $catalogMethod)
                                        <button
                                            type="button"
                                            role="option"
                                            aria-selected="{{ $catalogMethod['id'] === $method ? 'true' : 'false' }}"
                                            data-faker-option
                                            data-search="{{ strtolower($catalogMethod['id'].' '.implode(' ', $catalogMethod['aliases'])) }}"
                                            wire:click="setSchemaMethod('{{ $rowPath }}', '{{ $catalogMethod['id'] }}')"
                                            title="Sample: {{ is_scalar($catalogMethod['sample']) ? $catalogMethod['sample'] : json_encode($catalogMethod['sample']) }}"
                                        >
                                            <span><code>{{ $catalogMethod['id'] }}</code><small>{{ is_scalar($catalogMethod['sample']) ? Str::limit((string) $catalogMethod['sample'], 42) : Str::limit(json_encode($catalogMethod['sample']), 42) }}</small></span>
                                            @if ($catalogMethod['aliases'] !== [])<em class="safe-badge">Renamed aliases</em>@endif
                                        </button>
                                    @endforeach
                                </section>
                            @endforeach
                        </div>
                    </div>
                </details>
                <details class="schema-args">
                    <summary class="icon-button" title="Configure Faker arguments" aria-label="Configure Faker arguments">⚙</summary>
                    <div>
                        @if (($selectedMethod['argsHint'] ?? []) !== [])
                            <div class="schema-argument-fields">
                                @foreach ($selectedMethod['argsHint'] as $argumentName => $argumentHint)
                                    <label>
                                        {{ Str::headline($argumentName) }}
                                        @if (($argumentHint['type'] ?? '') === 'array')
                                            <textarea
                                                class="code-input compact"
                                                rows="2"
                                                placeholder='["one","two"]'
                                                wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.args_options.{{ $argumentName }}"
                                            ></textarea>
                                        @else
                                            <input
                                                type="{{ in_array($argumentHint['type'] ?? '', ['integer', 'number'], true) ? 'number' : 'text' }}"
                                                @if (($argumentHint['type'] ?? '') === 'number') step="any" @endif
                                                @if (isset($argumentHint['min'])) min="{{ $argumentHint['min'] }}" @endif
                                                @if (isset($argumentHint['max'])) max="{{ $argumentHint['max'] }}" @endif
                                                @if (isset($argumentHint['maxLength'])) maxlength="{{ $argumentHint['maxLength'] }}" @endif
                                                @if ($argumentHint['required'] ?? false) required @endif
                                                wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.args_options.{{ $argumentName }}"
                                            >
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        @endif
                        <label>JSON arguments
                            <textarea class="code-input compact" rows="3" placeholder='[{"min":1,"max":9}]' wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.args"></textarea>
                            <span class="field-help">Raw JSON overrides the option fields above.</span>
                        </label>
                    </div>
                </details>
            @elseif ($type === 'string')
                <select aria-label="String mode" wire:model.live="builderSchema.{{ $rowPath }}.mode">
                    <option value="fixed">Fixed</option>
                    <option value="interpolated">Interpolated</option>
                </select>
                <input type="text" placeholder="Value" wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.value">
            @elseif ($type === 'number')
                <select aria-label="Number mode" wire:model.live="builderSchema.{{ $rowPath }}.mode">
                    <option value="fixed">Fixed</option>
                    <option value="int">Random integer</option>
                    <option value="float">Random decimal</option>
                </select>
                @if (($row['mode'] ?? 'fixed') === 'fixed')
                    <input type="number" step="any" placeholder="0" wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.value">
                @else
                    <input type="number" step="any" aria-label="Minimum" placeholder="Min" wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.min">
                    <input type="number" step="any" aria-label="Maximum" placeholder="Max" wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.max">
                    @if (($row['mode'] ?? '') === 'float')
                        <input type="number" min="0" max="10" aria-label="Decimal places" placeholder="Decimals" wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.decimals">
                    @endif
                @endif
            @elseif ($type === 'boolean')
                <select aria-label="Boolean value" wire:model.live="builderSchema.{{ $rowPath }}.mode">
                    <option value="true">True</option>
                    <option value="false">False</option>
                    <option value="random">Random</option>
                </select>
            @elseif ($type === 'date')
                <select aria-label="Date mode" wire:model.live="builderSchema.{{ $rowPath }}.mode">
                    <option value="recent">Recent</option>
                    <option value="past">Past</option>
                    <option value="future">Future</option>
                    <option value="between">Between</option>
                    <option value="fixed">Fixed</option>
                </select>
                @if (($row['mode'] ?? 'recent') === 'fixed')
                    <input type="datetime-local" aria-label="Fixed date" wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.value">
                @elseif (($row['mode'] ?? 'recent') === 'between')
                    <input type="text" aria-label="From date" placeholder="From" wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.from">
                    <input type="text" aria-label="To date" placeholder="To" wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.to">
                @else
                    <input type="number" min="1" aria-label="Date amount" wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.{{ ($row['mode'] ?? 'recent') === 'recent' ? 'days' : 'years' }}">
                @endif
                @if (($row['mode'] ?? 'recent') !== 'fixed')
                    <select aria-label="Date output format" wire:model.live="builderSchema.{{ $rowPath }}.format">
                        <option value="iso">ISO-8601</option>
                        <option value="date">Date</option>
                        <option value="epochMs">Epoch ms</option>
                        <option value="epochS">Epoch s</option>
                    </select>
                @endif
            @elseif ($type === 'object')
                <span class="schema-type-summary">{{ count($row['children'] ?? []) }} {{ Str::plural('field', count($row['children'] ?? [])) }}</span>
            @elseif ($type === 'array')
                <select aria-label="Array length mode" wire:model.live="builderSchema.{{ $rowPath }}.length_mode">
                    <option value="fixed">Fixed length</option>
                    <option value="range">Min–max length</option>
                </select>
                @if (($row['length_mode'] ?? 'fixed') === 'range')
                    <input type="number" min="0" max="1000" aria-label="Minimum length" placeholder="Min" wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.length_min">
                    <input type="number" min="0" max="1000" aria-label="Maximum length" placeholder="Max" wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.length_max">
                @else
                    <input type="number" min="0" max="1000" aria-label="Array length" placeholder="Length" wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.length">
                @endif
            @endif
        </div>

        <label class="schema-nullable" title="Probability that this field is null">
            <span>Null %</span>
            <input type="number" min="0" max="100" wire:model.live.debounce.400ms="builderSchema.{{ $rowPath }}.nullable">
        </label>

        @if ($showKey)
            <div class="schema-row-actions" aria-label="Field actions">
                <button class="icon-button" type="button" wire:click="moveSchemaRow('{{ $parentPath }}', {{ $rowIndex }}, -1)" aria-label="Move field up" @disabled($rowIndex === 0)>↑</button>
                <button class="icon-button" type="button" wire:click="moveSchemaRow('{{ $parentPath }}', {{ $rowIndex }}, 1)" aria-label="Move field down" @disabled($rowIndex >= $rowCount - 1)>↓</button>
                <button class="icon-button danger" type="button" wire:click="removeSchemaRow('{{ $parentPath }}', {{ $rowIndex }})" aria-label="Delete field">×</button>
            </div>
        @endif
    </div>

    @if ($type === 'object')
        <details class="schema-nested" open>
            <summary><span class="details-chevron" aria-hidden="true">›</span>Object fields</summary>
            <div class="schema-children">
                @foreach (($row['children'] ?? []) as $childIndex => $childRow)
                    @include('livewire.admin.partials.schema-row', [
                        'row' => $childRow,
                        'rowPath' => $rowPath.'.children.'.$childIndex,
                        'parentPath' => $rowPath.'.children',
                        'rowIndex' => $childIndex,
                        'depth' => $depth + 1,
                        'showKey' => true,
                    ])
                @endforeach
                <button class="text-button" type="button" wire:click="addObjectChild('{{ $rowPath }}')">+ Add nested field</button>
            </div>
        </details>
    @elseif ($type === 'array')
        <div class="schema-array-item">
            <span class="select-caption">Item type</span>
            @if (is_array($row['item'] ?? null))
                @include('livewire.admin.partials.schema-row', [
                    'row' => $row['item'],
                    'rowPath' => $rowPath.'.item',
                    'parentPath' => $rowPath,
                    'rowIndex' => 0,
                    'depth' => $depth + 1,
                    'showKey' => false,
                ])
            @else
                <button class="button button-secondary button-small" type="button" wire:click="initializeArrayItem('{{ $rowPath }}')">Choose item type</button>
            @endif
        </div>
    @endif
</article>
