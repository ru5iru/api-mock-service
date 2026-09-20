<?php

namespace App\Livewire\Admin;

use App\Models\MockEndpoint;
use App\Services\Curl\CurlParser;
use App\Services\Curl\MockCurlBuilder;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\WithPagination;

final class EndpointIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $method = '';

    public string $state = 'all';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedMethod(): void
    {
        $this->resetPage();
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'method', 'state']);
        $this->state = 'all';
        $this->resetPage();
    }

    public function toggleEnabled(int $endpointId): void
    {
        $endpoint = MockEndpoint::query()->findOrFail($endpointId);
        $endpoint->update(['enabled' => ! $endpoint->enabled]);
        session()->flash('status', $endpoint->enabled ? 'Mock endpoint enabled.' : 'Mock endpoint disabled.');
    }

    public function delete(int $endpointId): void
    {
        MockEndpoint::query()->findOrFail($endpointId)->delete();
        session()->flash('status', 'Mock endpoint deleted.');
    }

    public function render(): View
    {
        $term = trim($this->search);
        $method = in_array($this->method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)
            ? $this->method
            : '';
        $state = in_array($this->state, ['enabled', 'disabled'], true) ? $this->state : 'all';
        $endpoints = MockEndpoint::query()
            ->withCount('responses')
            ->when($term !== '', fn ($query) => $query->where(function ($query) use ($term): void {
                $needle = '%'.strtolower($term).'%';
                $query->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(method) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(raw_curl) LIKE ?', [$needle]);
            }))
            ->when($method !== '', fn ($query) => $query->where('method', $method))
            ->when($state !== 'all', fn ($query) => $query->where('enabled', $state === 'enabled'))
            ->latest('updated_at')
            ->paginate(15);

        $parser = app(CurlParser::class);
        $builder = app(MockCurlBuilder::class);
        $mockCurls = $endpoints->getCollection()->mapWithKeys(function (MockEndpoint $endpoint) use ($parser, $builder): array {
            try {
                return [$endpoint->id => $builder->build($parser->parse($endpoint->raw_curl))];
            } catch (InvalidArgumentException) {
                return [$endpoint->id => null];
            }
        });

        return view('livewire.admin.endpoint-index', compact('endpoints', 'mockCurls'));
    }
}
