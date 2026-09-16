<?php

namespace App\Livewire\Admin;

use App\Models\MockEndpoint;
use App\Services\Curl\CurlParser;
use App\Services\Curl\MockCurlBuilder;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

final class EndpointIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function delete(int $endpointId): void
    {
        MockEndpoint::query()->findOrFail($endpointId)->delete();
        session()->flash('status', 'Mock endpoint deleted.');
    }

    public function render(): View
    {
        $term = trim($this->search);
        $endpoints = MockEndpoint::query()
            ->withCount('responses')
            ->when($term !== '', fn ($query) => $query->where(function ($query) use ($term): void {
                $needle = '%'.strtolower($term).'%';
                $query->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(method) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(raw_curl) LIKE ?', [$needle]);
            }))
            ->latest('updated_at')
            ->paginate(15);

        $parser = app(CurlParser::class);
        $builder = app(MockCurlBuilder::class);
        $mockCurls = $endpoints->getCollection()->mapWithKeys(
            fn (MockEndpoint $endpoint): array => [$endpoint->id => $builder->build($parser->parse($endpoint->raw_curl))],
        );

        return view('livewire.admin.endpoint-index', compact('endpoints', 'mockCurls'));
    }
}
