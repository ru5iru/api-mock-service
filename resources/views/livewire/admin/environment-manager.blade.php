<div class="environment-management-grid">
    <aside class="card environment-list" aria-label="Environments">
        <form wire:submit="createEnvironment" class="compact-create-row">
            <label class="sr-only" for="new-environment">New environment name</label>
            <input id="new-environment" type="text" wire:model="newEnvironmentName" placeholder="New environment">
            <button class="button button-primary button-small" type="submit">Add</button>
        </form>
        @error('newEnvironmentName') <p class="field-error">{{ $message }}</p> @enderror

        <div class="environment-list-items">
            @foreach ($environments as $environment)
                <button type="button" wire:click="selectEnvironment({{ $environment->id }})" @class(['active' => $environment->id === $selectedEnvironmentId])>
                    <span><strong>{{ $environment->name }}</strong><small>{{ $environment->variables_count }} {{ Str::plural('variable', $environment->variables_count) }}</small></span>
                    <span>
                        @if ($environment->id === $activeEnvironmentId)<span class="state-chip enabled">Active</span>@endif
                        @if ($environment->is_default)<span class="tag-chip">Default</span>@endif
                    </span>
                </button>
            @endforeach
        </div>
    </aside>

    <div class="environment-editor">
        <section class="card form-card">
            <div class="card-heading tight">
                <div><h2>{{ $selectedEnvironment->name }}</h2><p>Names and variables are shared by every endpoint using this environment.</p></div>
                <div class="button-row">
                    <button class="button button-secondary button-small" type="button" wire:click="duplicateSelected">Duplicate</button>
                    @unless ($selectedEnvironment->is_default)
                        <button class="button button-tertiary button-small" type="button" wire:click="makeDefault">Make default</button>
                    @endunless
                </div>
            </div>

            <div class="inline-edit-row">
                <label class="field">
                    <span>Name</span>
                    <input type="text" wire:model="renameEnvironment">
                </label>
                <button class="button button-secondary" type="button" wire:click="renameSelected">Save name</button>
            </div>
            @error('renameEnvironment') <p class="field-error">{{ $message }}</p> @enderror

            <details class="normalized-details danger-zone">
                <summary><span class="details-chevron" aria-hidden="true">›</span> Delete environment</summary>
                <p>Deleting the active or default environment requires a replacement. Endpoints are not deleted.</p>
                <div class="inline-edit-row">
                    <label class="field">
                        <span>Replacement</span>
                        <select wire:model="replacementEnvironmentId">
                            <option value="">Choose when required</option>
                            @foreach ($environments->where('id', '!=', $selectedEnvironmentId) as $environment)
                                <option value="{{ $environment->id }}">{{ $environment->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button class="button button-danger" type="button" wire:click="deleteSelected" wire:confirm="Delete {{ $selectedEnvironment->name }}? Its variables and endpoint overrides will be removed.">Delete</button>
                </div>
                @error('replacementEnvironmentId') <p class="field-error">{{ $message }}</p> @enderror
            </details>
        </section>

        <section class="card form-card">
            <div class="card-heading"><div><h2>Variables</h2><p>Secret values are write-only and remain masked after saving.</p></div></div>

            <div class="table-scroll">
                <table class="header-table environment-variable-table">
                    <thead><tr><th scope="col">Key</th><th scope="col">Value</th><th scope="col">Secret</th><th scope="col">Actions</th></tr></thead>
                    <tbody>
                        @forelse ($selectedEnvironment->variables as $variable)
                            <tr wire:key="environment-variable-{{ $variable->id }}">
                                <td><input aria-label="Variable key" type="text" wire:model="variableDrafts.{{ $variable->id }}.key">@error('variableDrafts.'.$variable->id.'.key')<span class="field-error">{{ $message }}</span>@enderror</td>
                                <td><input aria-label="Variable value" type="{{ $variable->is_secret ? 'password' : 'text' }}" wire:model="variableDrafts.{{ $variable->id }}.value" placeholder="{{ $variable->is_secret ? '•••••••• · leave blank to keep' : '' }}" autocomplete="off">@error('variableDrafts.'.$variable->id.'.value')<span class="field-error">{{ $message }}</span>@enderror</td>
                                <td><input aria-label="Secret variable" type="checkbox" wire:model="variableDrafts.{{ $variable->id }}.is_secret"></td>
                                <td><div class="row-actions"><button class="button button-secondary button-small" type="button" wire:click="saveVariable({{ $variable->id }})">Save</button><button class="icon-button danger" type="button" wire:click="deleteVariable({{ $variable->id }})" wire:confirm="Delete {{ $variable->key }}?">Delete</button></div></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="empty-table">No variables in this environment.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr>
                            <td><input aria-label="New variable key" type="text" wire:model="newVariableKey" placeholder="API_BASE_URL"></td>
                            <td><input aria-label="New variable value" type="{{ $newVariableSecret ? 'password' : 'text' }}" wire:model="newVariableValue" placeholder="Value" autocomplete="off"></td>
                            <td><input aria-label="New variable is secret" type="checkbox" wire:model.live="newVariableSecret"></td>
                            <td><button class="button button-primary button-small" type="button" wire:click="addVariable">Add variable</button></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @error('newVariableKey') <p class="field-error">{{ $message }}</p> @enderror
            @error('newVariableValue') <p class="field-error">{{ $message }}</p> @enderror
        </section>
    </div>
</div>
