<?php

namespace App\Livewire\Admin;

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Services\Environments\EnvironmentContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;

final class EnvironmentManager extends Component
{
    public int $selectedEnvironmentId;

    public string $newEnvironmentName = '';

    public string $renameEnvironment = '';

    public ?int $replacementEnvironmentId = null;

    public string $newVariableKey = '';

    public string $newVariableValue = '';

    public bool $newVariableSecret = false;

    /** @var array<int, array{key: string, value: string, is_secret: bool}> */
    public array $variableDrafts = [];

    public function mount(EnvironmentContext $context): void
    {
        $this->selectEnvironment($context->active()->id);
    }

    public function selectEnvironment(int $environmentId): void
    {
        $environment = Environment::query()->with('variables')->findOrFail($environmentId);
        $this->selectedEnvironmentId = $environment->id;
        $this->renameEnvironment = $environment->name;
        $this->variableDrafts = $environment->variables->mapWithKeys(static fn (EnvironmentVariable $variable): array => [
            $variable->id => [
                'key' => $variable->key,
                'value' => $variable->is_secret ? '' : (string) $variable->value,
                'is_secret' => $variable->is_secret,
            ],
        ])->all();
        $this->resetValidation();
    }

    #[On('environment-changed')]
    public function refreshActiveEnvironment(): void {}

    public function createEnvironment(): void
    {
        $this->newEnvironmentName = trim($this->newEnvironmentName);
        $data = $this->validate([
            'newEnvironmentName' => ['required', 'string', 'max:120', Rule::unique('environments', 'name')],
        ]);
        $environment = Environment::query()->create(['name' => trim($data['newEnvironmentName'])]);
        $this->newEnvironmentName = '';
        $this->selectEnvironment($environment->id);
        $this->dispatch('toast', message: 'Environment created.');
    }

    public function renameSelected(): void
    {
        $environment = $this->selected();
        $this->renameEnvironment = trim($this->renameEnvironment);
        $data = $this->validate([
            'renameEnvironment' => ['required', 'string', 'max:120', Rule::unique('environments', 'name')->ignore($environment->id)],
        ]);
        $environment->update(['name' => trim($data['renameEnvironment'])]);
        $this->dispatch('toast', message: 'Environment renamed.');
    }

    public function makeDefault(EnvironmentContext $context): void
    {
        $context->makeDefault($this->selected());
        $this->dispatch('toast', message: 'Default environment updated.');
    }

    public function duplicateSelected(): void
    {
        $source = $this->selected()->load('variables');
        $copy = DB::transaction(function () use ($source): Environment {
            $base = $source->name.' copy';
            $name = $base;
            $number = 2;
            while (Environment::query()->where('name', $name)->exists()) {
                $name = $base.' '.$number++;
            }
            $copy = Environment::query()->create(['name' => $name]);
            foreach ($source->variables as $variable) {
                $copy->variables()->create([
                    'key' => $variable->key,
                    'value' => $variable->value,
                    'is_secret' => $variable->is_secret,
                ]);
            }

            return $copy;
        }, 3);
        $this->selectEnvironment($copy->id);
        $this->dispatch('toast', message: 'Independent environment copy created.');
    }

    public function deleteSelected(EnvironmentContext $context): void
    {
        $environment = $this->selected();
        $replacement = $this->replacementEnvironmentId
            ? Environment::query()->findOrFail($this->replacementEnvironmentId)
            : null;
        try {
            $context->delete($environment, $replacement);
        } catch (InvalidArgumentException $exception) {
            $this->addError('replacementEnvironmentId', $exception->getMessage());

            return;
        }
        $this->replacementEnvironmentId = null;
        $this->selectEnvironment($context->active()->id);
        $this->dispatch('toast', message: 'Environment deleted.');
    }

    public function addVariable(): void
    {
        $data = $this->validate([
            'newVariableKey' => [
                'required', 'string', 'max:120', 'regex:/^[A-Za-z_][A-Za-z0-9_.-]*$/',
                Rule::unique('environment_variables', 'key')->where('environment_id', $this->selectedEnvironmentId),
            ],
            'newVariableValue' => ['required', 'string', 'max:65535'],
            'newVariableSecret' => ['boolean'],
        ]);
        $variable = $this->selected()->variables()->create([
            'key' => $data['newVariableKey'],
            'value' => $data['newVariableValue'],
            'is_secret' => $data['newVariableSecret'],
        ]);
        $this->newVariableKey = '';
        $this->newVariableValue = '';
        $this->newVariableSecret = false;
        $this->selectEnvironment($this->selectedEnvironmentId);
        $this->dispatch('toast', message: $variable->key.' added.');
    }

    public function saveVariable(int $variableId): void
    {
        $variable = $this->selected()->variables()->findOrFail($variableId);
        $draft = $this->variableDrafts[$variableId] ?? [];
        validator($draft, [
            'key' => [
                'required', 'string', 'max:120', 'regex:/^[A-Za-z_][A-Za-z0-9_.-]*$/',
                Rule::unique('environment_variables', 'key')->where('environment_id', $this->selectedEnvironmentId)->ignore($variable->id),
            ],
            'value' => ['nullable', 'string', 'max:65535'],
            'is_secret' => ['required', 'boolean'],
        ])->validate();

        if ($variable->is_secret && ! $draft['is_secret'] && $draft['value'] === '') {
            $this->addError("variableDrafts.{$variableId}.value", 'Enter a replacement value before making a secret variable public.');

            return;
        }

        $attributes = ['key' => $draft['key'], 'is_secret' => $draft['is_secret']];
        if (! ($variable->is_secret && $draft['value'] === '')) {
            $attributes['value'] = $draft['value'];
        }
        $variable->update($attributes);
        $this->selectEnvironment($this->selectedEnvironmentId);
        $this->dispatch('toast', message: $variable->key.' saved.');
    }

    public function deleteVariable(int $variableId): void
    {
        $variable = $this->selected()->variables()->findOrFail($variableId);
        $key = $variable->key;
        $variable->delete();
        $this->selectEnvironment($this->selectedEnvironmentId);
        $this->dispatch('toast', message: $key.' deleted.');
    }

    public function render(EnvironmentContext $context): View
    {
        return view('livewire.admin.environment-manager', [
            'environments' => Environment::query()->withCount('variables')->orderByDesc('is_default')->orderBy('name')->get(),
            'selectedEnvironment' => $this->selected()->load('variables'),
            'activeEnvironmentId' => $context->active()->id,
        ]);
    }

    private function selected(): Environment
    {
        return Environment::query()->findOrFail($this->selectedEnvironmentId);
    }
}
