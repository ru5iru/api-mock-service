<?php

namespace App\Livewire\Admin;

use App\Models\MockEndpoint;
use App\Services\Config\ConfigExporter;
use App\Services\Config\ConfigImporter;
use App\Services\Config\ImportMode;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ConfigTransfer extends Component
{
    use WithFileUploads;

    /** @var list<string> */
    public array $selectedEndpointUuids = [];

    public bool $redactSecrets = true;

    public bool $confirmSensitiveExport = false;

    public ?TemporaryUploadedFile $configFile = null;

    public string $mode = 'create-only';

    public bool $replaceResponses = false;

    public bool $acknowledgeWarnings = false;

    /** @var array<string, mixed> */
    public array $plan = [];

    /** @var array<string, int> */
    public array $summary = [];

    public function selectAll(): void
    {
        $this->selectedEndpointUuids = MockEndpoint::query()->orderBy('uuid')->pluck('uuid')->all();
    }

    public function clearSelection(): void
    {
        $this->selectedEndpointUuids = [];
    }

    public function updatedMode(): void
    {
        $this->clearPreview();
    }

    public function updatedReplaceResponses(): void
    {
        $this->clearPreview();
    }

    public function updatedConfigFile(): void
    {
        $this->clearPreview();
        $this->resetValidation();
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
            'replaceResponses' => ['boolean'],
        ]);

        if (strtolower((string) $this->configFile?->getClientOriginalExtension()) !== 'json') {
            $this->addError('configFile', 'Choose a .json MockDeck configuration file.');

            return;
        }

        $json = file_get_contents($this->configFile->getRealPath());
        if ($json === false) {
            $this->addError('configFile', 'The uploaded configuration could not be read.');

            return;
        }

        $this->plan = $importer
            ->preview($json, ImportMode::from($this->mode), $this->replaceResponses)
            ->toArray();
        $this->summary = [];
        $this->acknowledgeWarnings = false;
    }

    public function apply(ConfigImporter $importer): void
    {
        if ($this->plan === [] || ! ($this->plan['can_apply'] ?? false)) {
            $this->addError('import', 'Preview a valid configuration before importing.');

            return;
        }

        try {
            $this->summary = $importer
                ->apply(
                    (string) $this->plan['token'],
                    (string) $this->plan['digest'],
                    $this->acknowledgeWarnings,
                )
                ->toArray();
        } catch (InvalidArgumentException $exception) {
            $this->addError('import', $exception->getMessage());

            return;
        }

        $this->plan = [];
        $this->configFile = null;
        session()->flash('status', 'Configuration imported successfully.');
    }

    public function render(): View
    {
        return view('livewire.admin.config-transfer', [
            'endpoints' => MockEndpoint::query()
                ->withCount('responses')
                ->orderBy('name')
                ->orderBy('uuid')
                ->get(),
            'modes' => ImportMode::cases(),
        ]);
    }

    /** @param list<string>|null $endpointUuids */
    private function download(ConfigExporter $exporter, ?array $endpointUuids): ?StreamedResponse
    {
        $this->resetErrorBag('selectedEndpointUuids');

        if (! $this->redactSecrets && ! $this->confirmSensitiveExport) {
            $this->addError('redactSecrets', 'Confirm that the unredacted export may contain credentials.');

            return null;
        }

        try {
            $json = $exporter->export($endpointUuids, $this->redactSecrets)->toJson();
        } catch (InvalidArgumentException $exception) {
            $this->addError('export', $exception->getMessage());

            return null;
        }
        $filename = 'mockdeck-config-'.now()->utc()->format('Y-m-d').'.json';

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
        $this->acknowledgeWarnings = false;
    }
}
