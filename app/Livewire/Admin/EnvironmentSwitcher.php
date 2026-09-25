<?php

namespace App\Livewire\Admin;

use App\Models\Environment;
use App\Services\Environments\EnvironmentContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

final class EnvironmentSwitcher extends Component
{
    public int $activeEnvironmentId;

    public function mount(EnvironmentContext $context): void
    {
        $this->activeEnvironmentId = $context->active()->id;
    }

    public function activate(int $environmentId, EnvironmentContext $context): void
    {
        $environment = Environment::query()->findOrFail($environmentId);
        $context->activate($environment);
        $this->activeEnvironmentId = $environment->id;
        $this->dispatch('environment-changed', environmentId: $environment->id);
        $this->dispatch('toast', message: $environment->name.' is now active.');
    }

    #[On('environment-changed')]
    public function syncActive(int $environmentId): void
    {
        $this->activeEnvironmentId = $environmentId;
    }

    public function render(): View
    {
        return view('livewire.admin.environment-switcher', [
            'environments' => Environment::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'active' => Environment::query()->find($this->activeEnvironmentId),
        ]);
    }
}
