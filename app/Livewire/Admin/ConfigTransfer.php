<?php

namespace App\Livewire\Admin;

use App\Models\Collection;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\Revision;
use App\Services\Config\ConfigExporter;
use App\Services\Config\ConfigImporter;
use App\Services\Config\ImportMode;
use App\Services\Imports\MappedImportService;
use App\Services\Imports\PostmanMapper;
use App\Services\Revisions\RevisionManager;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection as SupportCollection;
use InvalidArgumentException;
use JsonException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ConfigTransfer extends Component
{
    use WithFileUploads;

    private const DEFAULT_IMPORT_MODE = 'create-only';

    /** @var list<string> */
    public array $selectedEndpointUuids = [];

    public string $exportSearch = '';

    public bool $redactSecrets = true;

    public bool $showRedactionPreview = false;

    public bool $confirmSensitiveExport = false;

    public string $exportScope = 'all';

    public string $exportCollectionId = '';

    public string $exportEnvironmentId = '';

    public ?TemporaryUploadedFile $configFile = null;

    public string $mode = self::DEFAULT_IMPORT_MODE;

    public string $format = 'native';

    public bool $maskImportedSecrets = false;

    public bool $replaceResponses = false;

    public bool $acknowledgeWarnings = false;

    /** @var array<string, mixed> */
    public array $plan = [];

    /** @var array<string, int|string|null> */
    public array $summary = [];

    public bool $importUndone = false;

    /** @var list<string> */
    public array $importedEndpointUuids = [];

    public function selectAll(): void
    {
        $this->selectedEndpointUuids = $this->exportQuery()->pluck('uuid')->all();
    }

    public function clearSelection(): void
    {
        $this->selectedEndpointUuids = [];
    }

    public function selectFormat(string $format): void
    {
        if (! in_array($format, ['native', 'postman'], true)) {
            return;
        }
        $this->format = $format;
        $this->removeFile();
        $this->maskImportedSecrets = false;
    }

    public function maskSecrets(): void
    {
        $this->maskImportedSecrets = true;
        $this->preview(app(ConfigImporter::class));
    }

    public function updatedMode(): void
    {
        $this->clearPreview();
    }

    public function updatedExportScope(): void
    {
        $this->clearSelection();
    }

    public function updatedExportCollectionId(): void
    {
        $this->clearSelection();
    }

    public function updatedExportEnvironmentId(): void
    {
        $this->clearSelection();
    }

    public function updatedReplaceResponses(): void
    {
        $this->clearPreview();
    }

    public function updatedConfigFile(): void
    {
        $this->clearPreview();
        $this->maskImportedSecrets = false;
        $this->resetValidation();

        if ($this->configFile === null) {
            return;
        }

        $this->validateOnly('configFile', [
            'configFile' => ['required', 'file', 'max:'.(int) ceil(config('mock.portable_config.max_bytes', 2097152) / 1024)],
        ]);

        if (strtolower((string) $this->configFile->getClientOriginalExtension()) !== 'json') {
            $this->addError('configFile', 'Choose a .json configuration file.');

            return;
        }

        $json = file_get_contents($this->configFile->getRealPath());
        try {
            json_decode((string) $json, true, $this->format === 'postman' ? 2048 : 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->addError('configFile', 'This file is not valid JSON: '.$exception->getMessage());
        }
    }

    public function removeFile(): void
    {
        $this->configFile = null;
        $this->clearPreview();
        $this->resetValidation('configFile');
    }

    public function switchToClone(): void
    {
        $this->mode = ImportMode::Clone->value;
        $this->clearPreview();
        $this->dispatch('toast', message: 'Clone mode selected. Preview the file again to generate new UUIDs.');
    }

    public function exportAll(ConfigExporter $exporter): ?StreamedResponse
    {
        return $this->download($exporter, null);
    }

    public function exportSelected(ConfigExporter $exporter): ?StreamedResponse
    {
        if ($this->selectedEndpointUuids === []) {
            $this->addError('selectedEndpointUuids', 'Select at least one endpoint to export.');

            return null;
        }

        return $this->download($exporter, $this->selectedEndpointUuids);
    }

    public function preview(ConfigImporter $importer): void
    {
        $this->validate([
            'configFile' => ['required', 'file', 'max:'.(int) ceil(config('mock.portable_config.max_bytes', 2097152) / 1024)],
            'mode' => ['required', 'in:create-only,upsert,clone'],
            'format' => ['required', 'in:native,postman'],
            'replaceResponses' => ['boolean'],
        ]);

        if (strtolower((string) $this->configFile?->getClientOriginalExtension()) !== 'json') {
            $this->addError('configFile', 'Choose a .json configuration file.');

            return;
        }

        $json = file_get_contents($this->configFile->getRealPath());
        if ($json === false) {
            $this->addError('configFile', 'The uploaded configuration could not be read.');

            return;
        }

        $this->plan = ($this->format === 'postman'
            ? app(MappedImportService::class)->preview(app(PostmanMapper::class), $json, ImportMode::from($this->mode), $this->maskImportedSecrets)
            : $importer->preview($json, ImportMode::from($this->mode), $this->replaceResponses))->toArray();
        $this->summary = [];
        $this->importUndone = false;
        $this->acknowledgeWarnings = false;
    }

    public function apply(ConfigImporter $importer): void
    {
        if ($this->plan === [] || ! ($this->plan['can_apply'] ?? false)) {
            $this->addError('import', 'Preview a valid configuration before importing.');

            return;
        }

        $mode = (string) ($this->plan['mode'] ?? $this->mode);
        $lastEndpointId = (int) (MockEndpoint::query()->max('id') ?? 0);
        $this->importedEndpointUuids = array_values(array_filter(array_map(
            static fn (array $item): ?string => in_array($item['action'] ?? null, ['create', 'update'], true)
                ? ($item['uuid'] ?? null)
                : null,
            $this->plan['items'] ?? [],
        )));

        try {
            $this->summary = (($this->plan['metadata']['format'] ?? 'native') === 'postman'
                ? app(MappedImportService::class)->apply((string) $this->plan['token'], (string) $this->plan['digest'])
                : $importer->apply((string) $this->plan['token'], (string) $this->plan['digest'], $this->acknowledgeWarnings))->toArray();
        } catch (InvalidArgumentException $exception) {
            $this->addError('import', $exception->getMessage());

            return;
        }

        if ($mode === ImportMode::Clone->value || ($this->plan['metadata']['format'] ?? 'native') === 'postman') {
            $this->importedEndpointUuids = array_values(array_unique([...$this->importedEndpointUuids, ...MockEndpoint::query()->where('id', '>', $lastEndpointId)->pluck('uuid')->all()]));
        }

        $this->plan = [];
        $this->configFile = null;
        session()->flash('status', 'Configuration imported successfully.');
        $this->dispatch('toast', message: 'Configuration imported successfully.');
    }

    public function undoImport(RevisionManager $revisions): void
    {
        $batchId = $this->summary['import_batch_id'] ?? null;
        if (! is_string($batchId) || $batchId === '') {
            $this->addError('import', 'This import has no version snapshots to restore.');

            return;
        }

        try {
            $revisions->undoImport($batchId);
        } catch (InvalidArgumentException $exception) {
            $this->addError('import', $exception->getMessage());

            return;
        }

        $this->importUndone = true;
        session()->flash('status', 'Import changes restored from version history.');
        $this->dispatch('toast', message: 'Import changes restored.');
    }

    public function render(): View
    {
        return view('livewire.admin.config-transfer', [
            'endpoints' => $this->exportQuery()->get(),
            'endpointTotal' => MockEndpoint::query()->count(),
            'importedEndpoints' => MockEndpoint::query()->whereIn('uuid', $this->importedEndpointUuids)->get(),
            'modes' => ImportMode::cases(),
            'collections' => Collection::query()->withCount('endpoints')->orderBy('name')->get(),
            'environments' => Environment::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'undoItems' => $this->undoItems(),
        ]);
    }

    /** @param list<string>|null $endpointUuids */
    private function download(ConfigExporter $exporter, ?array $endpointUuids): ?StreamedResponse
    {
        $this->resetErrorBag('selectedEndpointUuids');

        if ($this->exportScope === 'collection' && $this->exportCollectionId === '') {
            $this->addError('export', 'Choose a collection before exporting this scope.');

            return null;
        }
        if ($this->exportScope === 'environment' && $this->exportEnvironmentId === '') {
            $this->addError('export', 'Choose an environment before exporting this scope.');

            return null;
        }

        if (! $this->redactSecrets && ! $this->confirmSensitiveExport) {
            $this->addError('redactSecrets', 'Confirm that the unredacted export may contain credentials.');

            return null;
        }

        try {
            $collectionId = $this->exportScope === 'collection' && $this->exportCollectionId !== '' ? (int) $this->exportCollectionId : null;
            $environmentId = $this->exportScope === 'environment' && $this->exportEnvironmentId !== '' ? (int) $this->exportEnvironmentId : null;
            if ($collectionId !== null) {
                Collection::query()->findOrFail($collectionId);
            }
            if ($environmentId !== null) {
                Environment::query()->findOrFail($environmentId);
            }
            $json = $exporter->export($endpointUuids, $this->redactSecrets, $collectionId, $environmentId)->toJson();
        } catch (InvalidArgumentException $exception) {
            $this->addError('export', $exception->getMessage());

            return null;
        }
        $filename = 'mockdeck-export-'.now()->utc()->format('Ymd').'.json';

        return response()->streamDownload(
            static function () use ($json): void {
                echo $json;
            },
            $filename,
            ['Content-Type' => 'application/vnd.mockdeck.config+json; charset=UTF-8'],
        );
    }

    private function clearPreview(): void
    {
        $this->plan = [];
        $this->summary = [];
        $this->importUndone = false;
        $this->importedEndpointUuids = [];
        $this->acknowledgeWarnings = false;
    }

    /** @return Builder<MockEndpoint> */
    private function exportQuery(): Builder
    {
        $term = trim($this->exportSearch);

        return MockEndpoint::query()
            ->withCount('responses')
            ->when($this->exportScope === 'collection' && $this->exportCollectionId !== '', fn (Builder $query) => $query->where('collection_id', (int) $this->exportCollectionId))
            ->when($this->exportScope === 'environment' && $this->exportEnvironmentId !== '', function (Builder $query): void {
                $environmentId = (int) $this->exportEnvironmentId;
                $query->where(function (Builder $query) use ($environmentId): void {
                    $query->whereDoesntHave('environmentOverrides', fn ($override) => $override->whereKey($environmentId))
                        ->orWhereHas('environmentOverrides', fn ($override) => $override
                            ->whereKey($environmentId)
                            ->where('endpoint_environment_overrides.enabled', true));
                });
            })
            ->when($term !== '', function (Builder $query) use ($term): void {
                $needle = '%'.strtolower($term).'%';
                $query->where(function (Builder $query) use ($needle): void {
                    $query->whereRaw('LOWER(name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(method) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(raw_curl) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(normalized_curl) LIKE ?', [$needle]);
                });
            })
            ->orderBy('name')
            ->orderBy('uuid');
    }

    /** @return SupportCollection<int, string> */
    private function undoItems(): SupportCollection
    {
        $batchId = $this->summary['import_batch_id'] ?? null;
        if (! is_string($batchId) || $batchId === '') {
            return collect();
        }

        return Revision::query()
            ->where('import_batch_id', $batchId)
            ->where('source', 'import')
            ->orderBy('entity_type')
            ->orderBy('entity_id')
            ->get()
            ->map(static function (Revision $revision): string {
                return $revision->entity_type === 'endpoint'
                    ? (string) ($revision->snapshot['name'] ?: 'Endpoint '.$revision->entity_id)
                    : 'Response '.($revision->snapshot['status_code'] ?? $revision->entity_id);
            });
    }
}
