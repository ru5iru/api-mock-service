<?php

namespace App\Livewire\Admin;

use App\Models\Collection;
use App\Models\MockEndpoint;
use App\Models\Tag;
use App\Services\Config\ConfigExporter;
use App\Services\Curl\CurlParser;
use App\Services\Curl\MockCurlBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EndpointIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $method = '';

    public string $state = 'all';

    public string $sort = 'recent';

    public string $collection = 'all';

    /** @var list<int> */
    public array $tags = [];

    public string $bulkCollection = '';

    /** @var list<int> */
    public array $bulkTags = [];

    /** @var list<int> */
    public array $selected = [];

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

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function updatedCollection(): void
    {
        $this->resetPage();
    }

    public function updatedTags(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'method', 'state', 'sort', 'collection', 'tags']);
        $this->state = 'all';
        $this->sort = 'recent';
        $this->resetPage();
    }

    public function selectPage(): void
    {
        $ids = $this->endpointQuery()->paginate(15)->getCollection()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->selected = array_values(array_unique([...$this->selected, ...$ids]));
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function toggleEnabled(int $endpointId): void
    {
        $endpoint = MockEndpoint::query()->findOrFail($endpointId);
        $endpoint->update(['enabled' => ! $endpoint->enabled]);
        $this->dispatch('toast', message: $endpoint->displayName().' '.($endpoint->enabled ? 'enabled.' : 'disabled.'));
    }

    public function delete(int $endpointId): void
    {
        $endpoint = MockEndpoint::query()->findOrFail($endpointId);
        $name = $endpoint->displayName();
        $endpoint->delete();
        $this->selected = array_values(array_diff($this->selected, [$endpointId]));
        $this->dispatch('toast', message: $name.' and its responses were deleted.');
    }

    public function duplicate(int $endpointId): void
    {
        $source = MockEndpoint::query()->with(['responses', 'tags', 'environmentOverrides'])->findOrFail($endpointId);

        DB::transaction(function () use ($source): void {
            $copy = $source->replicate(['uuid']);
            $copy->name = 'Copy of '.$source->displayName();
            $copy->enabled = false;
            $copy->save();

            foreach ($source->responses as $response) {
                $copy->responses()->save($response->replicate(['uuid']));
            }
            $copy->tags()->sync($source->tags->modelKeys());
            $copy->environmentOverrides()->sync($source->environmentOverrides->mapWithKeys(
                static fn ($environment): array => [$environment->id => ['enabled' => (bool) $environment->pivot->enabled]],
            )->all());
        });

        $this->dispatch('toast', message: 'A disabled copy was created. Edit its request before enabling it.');
    }

    public function bulkMoveToCollection(): void
    {
        $collectionId = $this->bulkCollection === '' ? null : (int) $this->bulkCollection;
        if ($collectionId !== null) {
            Collection::query()->findOrFail($collectionId);
        }
        $ids = $this->selectedIds();
        MockEndpoint::query()->whereKey($ids)->update(['collection_id' => $collectionId]);
        $this->selected = [];
        $this->bulkCollection = '';
        $this->dispatch('toast', message: count($ids).' '.str('endpoint')->plural(count($ids)).' moved.');
    }

    public function bulkAddTags(): void
    {
        $tagIds = Tag::query()->whereKey(array_map('intval', $this->bulkTags))->pluck('id')->all();
        $ids = $this->selectedIds();
        foreach (MockEndpoint::query()->whereKey($ids)->get() as $endpoint) {
            $endpoint->tags()->syncWithoutDetaching($tagIds);
        }
        $this->selected = [];
        $this->bulkTags = [];
        $this->dispatch('toast', message: count($tagIds).' '.str('tag')->plural(count($tagIds)).' added.');
    }

    public function bulkSetEnabled(bool $enabled): void
    {
        $ids = $this->selectedIds();
        MockEndpoint::query()->whereKey($ids)->update(['enabled' => $enabled]);
        $this->selected = [];
        $this->dispatch('toast', message: count($ids).' '.str('endpoint')->plural(count($ids)).' '.($enabled ? 'enabled.' : 'disabled.'));
    }

    public function bulkDelete(): void
    {
        $ids = $this->selectedIds();
        MockEndpoint::query()->whereKey($ids)->delete();
        $this->selected = [];
        $this->dispatch('toast', message: count($ids).' '.str('endpoint')->plural(count($ids)).' and their responses were deleted.');
    }

    public function bulkExport(ConfigExporter $exporter): ?StreamedResponse
    {
        $ids = $this->selectedIds();
        if ($ids === []) {
            return null;
        }

        $uuids = MockEndpoint::query()->whereKey($ids)->pluck('uuid')->all();
        $json = $exporter->export($uuids, true)->toJson();

        return response()->streamDownload(
            static function () use ($json): void {
                echo $json;
            },
            'mockdeck-export-'.now()->utc()->format('Ymd').'.json',
            ['Content-Type' => 'application/vnd.mockdeck.config+json; charset=UTF-8'],
        );
    }

    public function render(): View
    {
        $endpoints = $this->endpointQuery()->paginate(15);

        $parser = app(CurlParser::class);
        $builder = app(MockCurlBuilder::class);
        $mockCurls = $endpoints->getCollection()->mapWithKeys(function (MockEndpoint $endpoint) use ($parser, $builder): array {
            try {
                return [$endpoint->id => $builder->build($parser->parse($endpoint->raw_curl))];
            } catch (InvalidArgumentException) {
                return [$endpoint->id => null];
            }
        });

        return view('livewire.admin.endpoint-index', [
            'endpoints' => $endpoints,
            'mockCurls' => $mockCurls,
            'collections' => Collection::query()->withCount('endpoints')->orderBy('name')->get(),
            'availableTags' => Tag::query()->withCount('endpoints')->orderBy('name')->get(),
        ]);
    }

    /** @return Builder<MockEndpoint> */
    private function endpointQuery(): Builder
    {
        $term = trim($this->search);
        $method = in_array($this->method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], true)
            ? $this->method
            : '';
        $state = in_array($this->state, ['enabled', 'disabled'], true) ? $this->state : 'all';
        $sort = in_array($this->sort, ['recent', 'name', 'priority'], true) ? $this->sort : 'recent';

        return MockEndpoint::query()
            ->with(['collection', 'tags'])
            ->withCount('responses')
            ->when($term !== '', fn ($query) => $query->where(function ($query) use ($term): void {
                $needle = '%'.strtolower($term).'%';
                $query->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(method) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(raw_curl) LIKE ?', [$needle]);
            }))
            ->when($method !== '', fn ($query) => $query->where('method', $method))
            ->when($state !== 'all', fn ($query) => $query->where('enabled', $state === 'enabled'))
            ->when($this->collection === 'none', fn ($query) => $query->whereNull('collection_id'))
            ->when(ctype_digit($this->collection), fn ($query) => $query->where('collection_id', (int) $this->collection))
            ->when($this->tags !== [], fn ($query) => $query->whereHas('tags', fn ($tags) => $tags->whereKey(array_map('intval', $this->tags))))
            ->when($sort === 'recent', fn ($query) => $query->latest('updated_at'))
            ->when($sort === 'name', fn ($query) => $query->orderByRaw("COALESCE(NULLIF(name, ''), method) ASC")->orderBy('id'))
            ->when($sort === 'priority', fn ($query) => $query->orderByDesc('priority')->latest('updated_at'));
    }

    /** @return list<int> */
    private function selectedIds(): array
    {
        return MockEndpoint::query()
            ->whereKey(array_map('intval', $this->selected))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
