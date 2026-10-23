<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\EditsResponseSelection;
use App\Models\CallbackAttempt;
use App\Models\MockEndpoint;
use App\Services\Callbacks\CallbackDispatcher;
use App\Services\Environments\EnvironmentContext;
use App\Services\Matching\PathPattern;
use App\Services\Mutations\CallbackConfigurationValidator;
use App\Services\Mutations\ResponseInput;
use App\Services\Mutations\ResponseMutationService;
use App\Services\Mutations\ResponseValidationException;
use App\Services\Templates\FakerMethodCatalog;
use App\Services\Templates\ResponseTemplateEngine;
use App\Services\Templates\TemplateContext;
use App\Services\Templates\TemplateRenderException;
use App\Services\Templates\TemplateSchemaConverter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

final class ResponseManager extends Component
{
    use EditsResponseSelection;

    private const FORM_FIELDS = ['editingId', 'statusCode', 'headersJson', 'body', 'bodyMode', 'template', 'editorView', 'seedMode', 'seed', 'locale', 'delayMs', 'weight', 'faultEnabled', 'faultType', 'faultDelayMsMin', 'faultDelayMsMax', 'faultProbability', 'callbackEnabled', 'callbackUrl', 'callbackMethod', 'callbackHeadersJson', 'callbackBody', 'callbackDelayMs', 'callbackDelayMaxMs', 'callbackRetry', 'callbackBackoffMs', 'callbackTimeoutMs', 'callbackSigningEnabled', 'callbackSigningSecret', 'callbackSignatureHeader'];

    #[Locked]
    public array $pendingFormDrafts = [];

    #[Locked]
    public array $formBaseline = [];

    public int $endpointId;

    private bool $savingDrafts = false;

    #[Locked]
    public string $savedFormFingerprint = '';

    #[Locked]
    public string $savedSelectionFingerprint = '';

    public ?int $editingId = null;

    public int $statusCode = 200;

    public string $headersJson = "{\n  \"Content-Type\": \"application/json\"\n}";

    public string $body = "{\n  \"ok\": true\n}";

    public string $bodyMode = 'static';

    public string $template = "{\n  \"\": \"\"\n}";

    public string $editorView = 'builder';

    public string $seedMode = 'random';

    public ?int $seed = null;

    public string $locale = 'en';

    /** @var array<string, mixed> */
    public array $builderSchema = [];

    public bool $builderSupported = true;

    /** @var list<array<string, mixed>> */
    public array $templateIssues = [];

    public string $previewOutput = '';

    public int $previewBytes = 0;

    public float $previewRenderMs = 0;

    public int $delayMs = 0;

    public int $weight = 1;

    public bool $faultEnabled = false;

    public string $faultType = 'delay';

    public int $faultDelayMsMin = 0;

    public ?int $faultDelayMsMax = null;

    public int $faultProbability = 100;

    public bool $callbackEnabled = false;

    public string $callbackUrl = '';

    public string $callbackMethod = 'POST';

    public string $callbackHeadersJson = "{\n  \"Content-Type\": \"application/json\"\n}";

    public string $callbackBody = "{\n  \"event\": \"created\"\n}";

    public string $callbackEditorView = 'json';

    /** @var array<string, mixed> */
    public array $callbackBuilderSchema = [];

    public bool $callbackBuilderSupported = true;

    public int $callbackDelayMs = 0;

    public ?int $callbackDelayMaxMs = null;

    public int $callbackRetry = 1;

    public int $callbackBackoffMs = 1000;

    public int $callbackTimeoutMs = 5000;

    public bool $callbackSigningEnabled = false;

    public string $callbackSigningSecret = '';

    public bool $callbackSecretSet = false;

    public string $callbackSignatureHeader = 'X-MockDeck-Signature';

    public string $callbackPreview = '';

    public ?string $callbackTestId = null;

    public string $callbackTestStatus = '';

    #[Locked]
    public ?string $previewPath = null;

    #[Locked]
    public bool $previewPathEnabled = false;

    #[On('endpoint-path-preview')]
    public function updateEndpointPathPreview(int $endpointId, string $path, bool $enabled): void
    {
        if ($endpointId !== $this->endpointId || strlen($path) > 8192 || ! str_starts_with($path, '/')) {
            return;
        }
        if ($enabled) {
            try {
                app(PathPattern::class)->validate($path);
            } catch (\InvalidArgumentException) {
                return;
            }
        }
        $this->previewPath = $path;
        $this->previewPathEnabled = $enabled;
        $this->clearPreview();
        $this->callbackPreview = '';
    }

    private function previewEndpoint(): MockEndpoint
    {
        $endpoint = clone $this->endpoint();
        if ($this->previewPath !== null) {
            $endpoint->normalized_curl = $endpoint->method."\n".$this->previewPath."\n\n";
            $endpoint->path_pattern_enabled = $this->previewPathEnabled;
        }

        return $endpoint;
    }

    public function updatedFaultType(): void
    {
        if ($this->faultType === 'timeout' && $this->faultDelayMsMin === 0) {
            $this->faultDelayMsMin = 60000;
            $this->faultDelayMsMax = null;
        }
    }

    public function mount(MockEndpoint $endpoint): void
    {
        $schemas = app(TemplateSchemaConverter::class);
        $this->endpointId = $endpoint->id;
        $this->loadSelection();
        $this->builderSchema = $schemas->emptySchema();
        $this->template = $schemas->schemaToTemplate($this->builderSchema);
        $this->updatedCallbackBody();
        $this->markFormBaseline();
    }

    public function edit(int $responseId): void
    {
        $this->stashCurrentForm();
        if (isset($this->pendingFormDrafts[$responseId])) {
            $this->restoreFormDraft($this->pendingFormDrafts[$responseId]);
            $this->resetValidation();
            $this->publishCallbackValidity();

            return;
        }
        $schemas = app(TemplateSchemaConverter::class);
        $templates = app(ResponseTemplateEngine::class);
        $response = $this->endpoint()->responses()->findOrFail($responseId);
        $this->editingId = $response->id;
        $this->statusCode = $response->status_code;
        $this->headersJson = json_encode(($response->headers ?? []) === [] ? new \stdClass : $response->headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->body = (string) ($response->body ?? '');
        $this->bodyMode = (string) ($response->body_mode ?? 'static');
        $this->template = (string) ($response->template ?? $schemas->schemaToTemplate($schemas->emptySchema()));
        $this->editorView = (string) ($response->editor_view ?? 'builder');
        $this->seedMode = (string) ($response->seed_mode ?? 'random');
        $this->seed = $response->seed === null ? null : (int) $response->seed;
        $this->locale = (string) ($response->locale ?? 'en');
        $projected = $schemas->templateToSchema($this->template);
        $this->builderSupported = $projected !== null;
        $this->builderSchema = $projected ?? $schemas->emptySchema();
        if (! $this->builderSupported && $this->editorView === 'builder') {
            $this->editorView = 'json';
        }
        $this->templateIssues = $templates->validate($this->template, $this->locale)->issueArrays();
        $this->clearPreview();
        $this->delayMs = $response->delay_ms;
        $this->weight = $response->weight;
        $this->faultEnabled = (bool) $response->fault_enabled;
        $this->faultType = (string) ($response->fault_type ?? 'delay');
        $this->faultDelayMsMin = (int) ($response->fault_delay_ms_min ?? 0);
        $this->faultDelayMsMax = $response->fault_delay_ms_max;
        $this->faultProbability = (int) ($response->fault_probability ?? 100);
        $this->callbackEnabled = (bool) $response->callback_enabled;
        $this->callbackUrl = (string) ($response->callback_url ?? '');
        $this->callbackMethod = (string) $response->callback_method;
        $this->callbackHeadersJson = json_encode($response->callback_headers ?? new \stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->callbackBody = (string) ($response->callback_body ?? '{}');
        $this->updatedCallbackBody();
        $this->callbackDelayMs = $response->callback_delay_ms;
        $this->callbackDelayMaxMs = $response->callback_delay_max_ms;
        $this->callbackRetry = $response->callback_retry;
        $this->callbackBackoffMs = $response->callback_backoff_ms;
        $this->callbackTimeoutMs = $response->callback_timeout_ms;
        $this->callbackSigningEnabled = (bool) $response->callback_signing_enabled;
        $this->callbackSigningSecret = '';
        $this->callbackSecretSet = $response->callback_signing_secret !== null;
        $this->callbackSignatureHeader = (string) $response->callback_signature_header;
        $this->callbackPreview = '';
        $this->callbackTestId = null;
        $this->callbackTestStatus = '';
        $this->resetValidation();
        $this->markFormBaseline();
        $this->publishCallbackValidity();
    }

    #[On('save-response-drafts')]
    public function savePendingDrafts(int $endpointId): void
    {
        if ($endpointId !== $this->endpointId) {
            return;
        }
        $this->stashCurrentForm();
        $drafts = $this->pendingFormDrafts;
        $selectionDraft = $this->selectionDraft();
        $selectionBaseline = $this->savedSelectionFingerprint;
        $this->resetValidation();
        $this->savingDrafts = true;
        try {
            DB::transaction(function () use ($drafts): void {
                if ($this->selectionFingerprint() !== $this->savedSelectionFingerprint) {
                    $this->saveSelection();
                }
                foreach ($drafts as $draft) {
                    $this->restoreFormDraft($draft);
                    $this->save();
                    if ($this->getErrorBag()->isNotEmpty()) {
                        throw ValidationException::withMessages($this->getErrorBag()->getMessages());
                    }
                }
            }, 3);
        } catch (ValidationException $exception) {
            $this->pendingFormDrafts = $drafts;
            foreach ($selectionDraft as $key => $value) {
                $this->{$key} = $value;
            }
            $this->savedSelectionFingerprint = $selectionBaseline;
            $this->setErrorBag($exception->errors());
            $tab = collect(array_keys($exception->errors()))->contains(fn ($key) => str_starts_with($key, 'callback')) ? 'callback' : 'response';
            $this->dispatch('show-editor-tab', tab: $tab)->to(EndpointForm::class);
            $this->dispatch('response-drafts-save-failed', endpointId: $this->endpointId)->to(EndpointForm::class);

            return;
        } finally {
            $this->savingDrafts = false;
        }
        $this->dispatch('response-drafts-saved', endpointId: $this->endpointId)->to(EndpointForm::class);
    }

    private function formState(): array
    {
        $fields = [...self::FORM_FIELDS, 'builderSchema', 'builderSupported', 'templateIssues', 'previewOutput', 'previewBytes', 'previewRenderMs', 'callbackEditorView', 'callbackBuilderSchema', 'callbackBuilderSupported', 'callbackSecretSet', 'callbackPreview'];

        return array_combine($fields, array_map(fn ($field) => $this->{$field}, $fields));
    }

    private function markFormBaseline(): void
    {
        $this->formBaseline = $this->formState();
        $this->savedFormFingerprint = $this->formFingerprint();
    }

    private function stashCurrentForm(): void
    {
        $key = $this->editingId ?? 'new';
        if ($this->formBaseline !== [] && $this->formFingerprint() !== $this->savedFormFingerprint) {
            $this->pendingFormDrafts[$key] = ['state' => $this->formState(), 'baseline' => $this->formBaseline, 'fingerprint' => $this->savedFormFingerprint];
        } else {
            unset($this->pendingFormDrafts[$key]);
        }
    }

    private function restoreFormDraft(array $draft): void
    {
        foreach ($draft['state'] as $field => $value) {
            $this->{$field} = $value;
        }
        $this->formBaseline = $draft['baseline'];
        $this->savedFormFingerprint = $draft['fingerprint'];
    }

    private function formFingerprint(?array $state = null): string
    {
        return hash('sha256', json_encode(array_map(fn ($field) => $state === null ? $this->{$field} : $state[$field], self::FORM_FIELDS), JSON_THROW_ON_ERROR));
    }

    private function selectionDraft(): array
    {
        $draft = [];
        foreach (['selectionMode', 'sequenceOnExhaust', 'responseOrder', 'fallbackResponseId', 'responseRules'] as $field) {
            $draft[$field] = $this->{$field};
        }

        return $draft;
    }

    private function selectionFingerprint(): string
    {
        return hash('sha256', json_encode($this->selectionDraft(), JSON_THROW_ON_ERROR));
    }

    public function editCallback(int $responseId, bool $enable = false): void
    {
        if ($this->editingId !== $responseId) {
            $this->edit($responseId);
        }
        if ($enable) {
            $this->callbackEnabled = true;
        }
        $this->publishCallbackValidity();
    }

    public function createNew(): void
    {
        $this->stashCurrentForm();
        $this->resetForm();
        if (isset($this->pendingFormDrafts['new'])) {
            $this->restoreFormDraft($this->pendingFormDrafts['new']);
        }
    }

    public function cancelEdit(): void
    {
        unset($this->pendingFormDrafts[$this->editingId ?? 'new']);
        $this->resetForm();
        if (isset($this->pendingFormDrafts['new'])) {
            $this->restoreFormDraft($this->pendingFormDrafts['new']);
        }
        $this->publishCallbackValidity();
    }

    #[On('revision-restored')]
    public function revisionRestored(string $entityType, int $entityId): void
    {
        if ($entityType === 'endpoint' && $entityId === $this->endpointId) {
            $this->loadSelection();

            return;
        }
        if ($entityType !== 'response') {
            return;
        }
        $this->loadSelection();
        unset($this->pendingFormDrafts[$entityId]);

        if ($this->editingId === $entityId) {
            $this->markFormBaseline();
            $this->edit($entityId);
        }
    }

    public function save(): void
    {
        $this->resetValidation();
        try {
            app(ResponseMutationService::class)->save($this->endpointId, $this->mutationInput(), $this->editingId);
        } catch (ResponseValidationException $exception) {
            if ($exception->templateIssues !== null) {
                $this->templateIssues = $exception->templateIssues;
            }
            $this->setErrorBag($exception->errors());

            return;
        }

        unset($this->pendingFormDrafts[$this->editingId ?? 'new']);
        $this->dispatch('toast', message: $this->editingId === null ? 'Response added.' : 'Response updated.');
        $this->resetForm();
        $this->loadSelection();
    }

    private function callbackRules(): array
    {
        return app(CallbackConfigurationValidator::class)->rules();
    }

    private function callbackValues(): array
    {
        $values = ['locale' => $this->locale];
        foreach (array_keys($this->callbackRules()) as $field) {
            $values[$field] = $this->{$field};
        }

        return $values;
    }

    public function callbackSettingsValid(?array $state = null): bool
    {
        try {
            $values = $state ?? $this->callbackValues();
            app(CallbackConfigurationValidator::class)->validate($values, $state['callbackSecretSet'] ?? $this->callbackSecretSet);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function publishCallbackValidity(): void
    {
        $valid = $this->endpoint()->responses()->exists() && $this->callbackSettingsValid();
        foreach ($this->pendingFormDrafts as $key => $draft) {
            if ((string) $key !== (string) ($this->editingId ?? 'new') && ! $this->callbackSettingsValid($draft['state'])) {
                $valid = false;
            }
        }
        $this->dispatch('callback-draft-validity', endpointId: $this->endpointId, valid: $valid)->to(EndpointForm::class);
    }

    public function saveCallback(): void
    {
        if ($this->editingId === null) {
            return;
        }
        $this->resetValidation();
        app(ResponseMutationService::class)->saveCallback($this->endpointId, $this->editingId, $this->callbackValues(), $this->callbackSecretSet);
        $this->callbackSecretSet = $this->callbackSigningSecret !== '' || $this->callbackSecretSet;
        $this->callbackSigningSecret = '';
        foreach (self::FORM_FIELDS as $field) {
            if (str_starts_with($field, 'callback')) {
                $this->formBaseline[$field] = $this->{$field};
            }
        }
        $this->savedFormFingerprint = $this->formFingerprint($this->formBaseline);
        $this->stashCurrentForm();
        $this->publishCallbackValidity();
        $this->dispatch('toast', message: 'Callback saved.');
    }

    public function delete(int $responseId): void
    {
        app(ResponseMutationService::class)->delete($this->endpointId, $responseId);
        unset($this->pendingFormDrafts[$responseId]);
        if ($this->editingId === $responseId) {
            $this->resetForm();
        }
        $this->loadSelection();
        $this->dispatch('toast', message: 'Response deleted.');
    }

    public function previewCallback(): void
    {
        $this->callbackPreview = '';
        try {
            $response = $this->editingId === null
                ? $this->endpoint()->responses()->make(['locale' => $this->locale, 'seed_mode' => $this->seedMode, 'seed' => $this->seed])
                : $this->endpoint()->responses()->findOrFail($this->editingId);
            $context = app(TemplateContext::class)->preview($this->callbackBody, $this->previewEndpoint());
            $this->callbackPreview = app(ResponseTemplateEngine::class)
                ->renderCallback($this->callbackBody, $response, $context, 'preview')->json;
            $this->resetErrorBag('callbackBody');
        } catch (\Throwable $exception) {
            if ($exception instanceof TemplateRenderException && $exception->issueCode === 'SECRET_PREVIEW_HIDDEN') {
                $this->callbackPreview = '[Preview hidden: the body references a secret environment variable.]';

                return;
            }
            // Do not reflect exception details, which may contain environment secrets.
            $this->addError('callbackBody', 'Preview failed. Check the JSON and available context tokens.');
        }
    }

    public function sendTestCallback(): void
    {
        if ($this->editingId === null || ! $this->endpoint()->responses()->findOrFail($this->editingId)->callback_enabled) {
            $this->addError('callbackEnabled', 'Save an enabled callback before testing it.');

            return;
        }

        $id = 'callback-test-'.Str::uuid();
        $this->callbackTestId = $id;
        $this->callbackTestStatus = 'Queued';
        app(CallbackDispatcher::class)->enqueue($this->editingId, $id, app(EnvironmentContext::class)->active()->id, app(TemplateContext::class)->synthetic($id, null, $this->endpoint()));
    }

    public function pollCallbackTest(): void
    {
        if ($this->callbackTestId === null) {
            return;
        }

        $attempt = CallbackAttempt::query()->where('request_log_id', $this->callbackTestId)->latest()->first();
        if ($attempt !== null) {
            $this->callbackTestStatus = ucfirst($attempt->status)
                .($attempt->http_status !== null ? ' · HTTP '.$attempt->http_status : '')
                .($attempt->duration_ms !== null ? ' · '.$attempt->duration_ms.' ms' : '');
        }
    }

    public function updatedTemplate(): void
    {
        $templates = app(ResponseTemplateEngine::class);
        $schemas = app(TemplateSchemaConverter::class);
        $this->templateIssues = $templates->validate($this->template, $this->locale)->issueArrays();
        $projected = $schemas->templateToSchema($this->template);
        $this->builderSupported = $projected !== null;
        if ($projected !== null) {
            $this->builderSchema = $projected;
        } elseif ($this->editorView === 'builder') {
            $this->editorView = 'json';
        }
        $this->clearPreview();
        if (! collect($this->templateIssues)->contains(static fn (array $issue): bool => $issue['severity'] === 'error')) {
            $this->previewTemplate();
        }
    }

    public function updatedLocale(): void
    {
        if ($this->bodyMode === 'template') {
            $this->templateIssues = app(ResponseTemplateEngine::class)->validate($this->template, $this->locale)->issueArrays();
            $this->clearPreview();
            if (! collect($this->templateIssues)->contains(static fn (array $issue): bool => $issue['severity'] === 'error')) {
                $this->previewTemplate();
            }
        }
    }

    public function updatedSeedMode(): void
    {
        if ($this->bodyMode === 'template' && $this->seedMode !== 'fixed') {
            $this->previewTemplate();
        }
    }

    public function updatedSeed(): void
    {
        if ($this->bodyMode === 'template' && $this->seedMode === 'fixed' && $this->seed !== null) {
            $this->previewTemplate();
        }
    }

    public function updatedBuilderSchema(): void
    {
        if ($this->editorView !== 'builder') {
            return;
        }

        $this->template = app(TemplateSchemaConverter::class)->schemaToTemplate($this->builderSchema);
        $this->templateIssues = app(ResponseTemplateEngine::class)->validate($this->template, $this->locale)->issueArrays();
        $this->builderSupported = true;
        $this->clearPreview();
        if (! collect($this->templateIssues)->contains(static fn (array $issue): bool => $issue['severity'] === 'error')) {
            $this->previewTemplate();
        }
    }

    public function updatedCallbackBody(): void
    {
        $schema = app(TemplateSchemaConverter::class)->templateToSchema($this->callbackBody);
        $hasContextTokens = str_contains($this->callbackBody, '$request.')
            || preg_match('/\{\{\s*(?:env|request)\./', $this->callbackBody) === 1;
        $this->callbackBuilderSupported = $schema !== null && ! $hasContextTokens;
        $this->callbackBuilderSchema = $schema ?? app(TemplateSchemaConverter::class)->emptySchema();
        if (! $this->callbackBuilderSupported) {
            $this->callbackEditorView = 'json';
        }
        $this->callbackPreview = '';
    }

    public function updatedCallbackBuilderSchema(): void
    {
        if ($this->callbackEditorView === 'builder') {
            $this->callbackBody = app(TemplateSchemaConverter::class)->schemaToTemplate($this->callbackBuilderSchema);
            $this->callbackBuilderSupported = true;
            $this->callbackPreview = '';
        }
    }

    public function setCallbackEditorView(string $view): void
    {
        if (! in_array($view, ['builder', 'json'], true) || ($view === 'builder' && ! $this->callbackBuilderSupported)) {
            return;
        }

        $this->callbackEditorView = $view;
        if ($view === 'builder') {
            $this->callbackBuilderSchema = app(TemplateSchemaConverter::class)->templateToSchema($this->callbackBody)
                ?? app(TemplateSchemaConverter::class)->emptySchema();
        }
    }

    public function setBodyMode(string $mode): void
    {
        if (in_array($mode, ['static', 'template'], true)) {
            $this->bodyMode = $mode;
            $this->resetValidation('bodyMode');
            if ($mode === 'template') {
                $this->updatedTemplate();
            }
        }
    }

    public function setEditorView(string $view): void
    {
        $schemas = app(TemplateSchemaConverter::class);
        if ($view === 'builder' && ! $this->builderSupported) {
            return;
        }
        if (! in_array($view, ['builder', 'json'], true)) {
            return;
        }

        $this->editorView = $view;
        if ($view === 'builder') {
            $projected = $schemas->templateToSchema($this->template);
            if ($projected !== null) {
                $this->builderSchema = $projected;
            }
        }
    }

    public function insertExample(): void
    {
        $templates = app(ResponseTemplateEngine::class);
        $schemas = app(TemplateSchemaConverter::class);
        $this->template = <<<'JSON'
{
  "username": "$internet.userName",
  "knownIps": ["$internet.ip", "$internet.ipv6"],
  "profile": {
    "firstName": "$name.firstName",
    "lastName": "$name.lastName",
    "staticData": [100, 200, 300]
  }
}
JSON;
        $this->editorView = 'json';
        $this->templateIssues = $templates->validate($this->template, $this->locale)->issueArrays();
        $this->builderSupported = $schemas->templateToSchema($this->template) !== null;
        $this->clearPreview();
    }

    public function previewTemplate(): void
    {
        $templates = app(ResponseTemplateEngine::class);
        try {
            $result = $templates->preview($this->template, $this->locale, $this->seedMode, $this->seed, $this->previewEndpoint());
            $this->templateIssues = $result['issues'];
            $this->previewOutput = $result['output'] === null
                ? ''
                : json_encode($result['output'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
            $this->previewBytes = $result['bytes'];
            $this->previewRenderMs = $result['render_ms'];
        } catch (TemplateRenderException $exception) {
            $this->templateIssues = [[
                'severity' => 'error',
                'code' => $exception->issueCode,
                'path' => $exception->templatePath,
                'line' => 1,
                'col' => 1,
                'message' => $exception->getMessage(),
                'suggestion' => null,
            ]];
            $this->clearPreview();
        }
    }

    public function applyTemplateSuggestion(string $message, string $suggestion): void
    {
        $from = null;
        $to = null;
        if (preg_match('/^(.+) was renamed to (.+)\.$/', $message, $matches) === 1) {
            [, $from, $to] = $matches;
        } elseif (preg_match('/^Unknown Faker method: ([A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*)\./', $message, $matches) === 1
            && preg_match('/([A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*)/', $suggestion, $suggestionMatch) === 1) {
            $from = $matches[1];
            $to = $suggestionMatch[1];
        }

        if ($from === null || $to === null) {
            return;
        }

        $this->template = str_replace($from, $to, $this->template);
        $this->updatedTemplate();
    }

    public function addSchemaRow(string $path, string $target = 'response'): void
    {
        $schemas = app(TemplateSchemaConverter::class);
        $schema = $target === 'callback' ? $this->callbackBuilderSchema : $this->builderSchema;
        $rows = data_get($schema, $path, []);
        if (! is_array($rows)) {
            return;
        }
        $rows[] = $schemas->emptyRow();
        data_set($schema, $path, $rows);
        $this->setSchema($schema, $target);
    }

    public function removeSchemaRow(string $path, int $index, string $target = 'response'): void
    {
        $schemas = app(TemplateSchemaConverter::class);
        $schema = $target === 'callback' ? $this->callbackBuilderSchema : $this->builderSchema;
        $rows = data_get($schema, $path, []);
        if (! is_array($rows) || ! array_key_exists($index, $rows)) {
            return;
        }
        array_splice($rows, $index, 1);
        if ($rows === []) {
            $rows[] = $schemas->emptyRow();
        }
        data_set($schema, $path, array_values($rows));
        $this->setSchema($schema, $target);
    }

    public function moveSchemaRow(string $path, int $index, int $direction, string $target = 'response'): void
    {
        $schema = $target === 'callback' ? $this->callbackBuilderSchema : $this->builderSchema;
        $rows = data_get($schema, $path, []);
        $targetIndex = $index + $direction;
        if (! is_array($rows) || ! isset($rows[$index]) || $targetIndex < 0 || $targetIndex >= count($rows)) {
            return;
        }
        [$rows[$index], $rows[$targetIndex]] = [$rows[$targetIndex], $rows[$index]];
        data_set($schema, $path, array_values($rows));
        $this->setSchema($schema, $target);
    }

    public function reorderSchemaRow(string $path, int $from, int $to, string $target = 'response'): void
    {
        if ($target === 'selection') {
            $this->reorderSelectionResponse($from, $to);

            return;
        }
        $schema = $target === 'callback' ? $this->callbackBuilderSchema : $this->builderSchema;
        $rows = data_get($schema, $path, []);
        if (! is_array($rows) || ! isset($rows[$from]) || ! isset($rows[$to]) || $from === $to) {
            return;
        }

        $row = array_splice($rows, $from, 1)[0];
        array_splice($rows, $to, 0, [$row]);
        data_set($schema, $path, array_values($rows));
        $this->setSchema($schema, $target);
    }

    public function setSchemaMethod(string $path, string $method, string $target = 'response'): void
    {
        $definition = app(FakerMethodCatalog::class)->definitions()[$method] ?? null;
        if ($definition === null) {
            return;
        }

        $options = [];
        foreach ($definition['argsHint'] ?? [] as $name => $hint) {
            $options[$name] = $hint['default'] ?? '';
        }
        $schema = $target === 'callback' ? $this->callbackBuilderSchema : $this->builderSchema;
        data_set($schema, $path.'.method', $method);
        data_set($schema, $path.'.type', 'faker');
        data_set($schema, $path.'.args', '');
        data_set($schema, $path.'.args_options', $options);
        $this->setSchema($schema, $target);
    }

    public function addObjectChild(string $path, string $target = 'response'): void
    {
        $schemas = app(TemplateSchemaConverter::class);
        $schema = $target === 'callback' ? $this->callbackBuilderSchema : $this->builderSchema;
        $childrenPath = $path.'.children';
        $children = data_get($schema, $childrenPath, []);
        $children[] = $schemas->emptyRow();
        data_set($schema, $childrenPath, $children);
        $this->setSchema($schema, $target);
    }

    public function initializeArrayItem(string $path, string $target = 'response'): void
    {
        $schemas = app(TemplateSchemaConverter::class);
        $schema = $target === 'callback' ? $this->callbackBuilderSchema : $this->builderSchema;
        if (! is_array(data_get($schema, $path.'.item'))) {
            data_set($schema, $path.'.item', $schemas->emptyRow());
        }
        $this->setSchema($schema, $target);
    }

    /** @param array<string, mixed> $schema */
    private function setSchema(array $schema, string $target): void
    {
        if ($target === 'callback') {
            $this->callbackBuilderSchema = $schema;
            $this->updatedCallbackBuilderSchema();

            return;
        }

        $this->builderSchema = $schema;
        $this->updatedBuilderSchema();
    }

    public function render(): View
    {
        $endpoint = $this->endpoint();
        $environment = app(EnvironmentContext::class)->active();
        $state = $endpoint->callStates()->where('environment_id', $environment->id)->first();
        $pool = $endpoint->responses()->with('rules')->get()->keyBy('id');
        $responses = collect($this->responseOrder)->map(fn ($id) => $pool->get($id))->filter()->values();
        $position = (int) ($state?->sequence_position ?? 0);
        $next = null;
        if ($responses->isNotEmpty()) {
            $index = $position;
            if ($index >= $responses->count()) {
                $index = match ($this->sequenceOnExhaust) {
                    'loop' => $index % $responses->count(),
                    'repeat_last' => $responses->count() - 1,
                    default => null,
                };
            }
            $next = $index === null ? null : $responses->get($index);
        }

        return view('livewire.admin.response-manager', [
            'responses' => $responses,
            'selectionDirty' => $this->selectionFingerprint() !== $this->savedSelectionFingerprint,
            'selectionEnvironment' => $environment,
            'sequencePosition' => $position,
            'nextSequenceResponse' => $next,
            'rulePreview' => $responses->filter(fn ($item) => $item->id !== $this->fallbackResponseId && ($this->responseRules[$item->id] ?? []) !== [])
                ->sortBy(fn ($item) => collect($this->responseRules[$item->id])->min('priority'))->values(),
            'fakerCatalog' => app(FakerMethodCatalog::class)->catalog(),
            'contextCatalog' => app(TemplateContext::class)->catalog($this->previewEndpoint()),
            'templateLocales' => config('mock.templates.locales', ['en']),
        ]);
    }

    private function endpoint(): MockEndpoint
    {
        return MockEndpoint::query()->findOrFail($this->endpointId);
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->statusCode = 200;
        $this->headersJson = "{\n  \"Content-Type\": \"application/json\"\n}";
        $this->body = "{\n  \"ok\": true\n}";
        $this->bodyMode = 'static';
        $this->template = "{\n  \"\": \"\"\n}";
        $this->editorView = 'builder';
        $this->seedMode = 'random';
        $this->seed = null;
        $this->locale = 'en';
        $this->builderSchema = app(TemplateSchemaConverter::class)->emptySchema();
        $this->template = app(TemplateSchemaConverter::class)->schemaToTemplate($this->builderSchema);
        $this->builderSupported = true;
        $this->templateIssues = [];
        $this->clearPreview();
        $this->delayMs = 0;
        $this->weight = 1;
        $this->faultEnabled = false;
        $this->faultType = 'delay';
        $this->faultDelayMsMin = 0;
        $this->faultDelayMsMax = null;
        $this->faultProbability = 100;
        $this->callbackEnabled = false;
        $this->callbackUrl = '';
        $this->callbackMethod = 'POST';
        $this->callbackHeadersJson = "{\n  \"Content-Type\": \"application/json\"\n}";
        $this->callbackBody = "{\n  \"event\": \"created\"\n}";
        $this->callbackEditorView = 'json';
        $this->updatedCallbackBody();
        $this->callbackDelayMs = 0;
        $this->callbackDelayMaxMs = null;
        $this->callbackRetry = 1;
        $this->callbackBackoffMs = 1000;
        $this->callbackTimeoutMs = 5000;
        $this->callbackSigningEnabled = false;
        $this->callbackSigningSecret = '';
        $this->callbackSecretSet = false;
        $this->callbackSignatureHeader = 'X-MockDeck-Signature';
        $this->callbackPreview = '';
        $this->callbackTestId = null;
        $this->callbackTestStatus = '';
        $this->resetValidation();
        $this->markFormBaseline();
    }

    private function clearPreview(): void
    {
        $this->previewOutput = '';
        $this->previewBytes = 0;
        $this->previewRenderMs = 0;
    }

    private function mutationInput(): ResponseInput
    {
        return new ResponseInput(
            statusCode: $this->statusCode,
            headersJson: $this->headersJson,
            body: $this->body,
            bodyMode: $this->bodyMode,
            template: $this->template,
            editorView: $this->editorView,
            seedMode: $this->seedMode,
            seed: $this->seed,
            locale: $this->locale,
            delayMs: $this->delayMs,
            weight: $this->weight,
            faultEnabled: $this->faultEnabled,
            faultType: $this->faultType,
            faultDelayMsMin: $this->faultDelayMsMin,
            faultDelayMsMax: $this->faultDelayMsMax,
            faultProbability: $this->faultProbability,
            callbackEnabled: $this->callbackEnabled,
            callbackUrl: $this->callbackUrl,
            callbackMethod: $this->callbackMethod,
            callbackHeadersJson: $this->callbackHeadersJson,
            callbackBody: $this->callbackBody,
            callbackDelayMs: $this->callbackDelayMs,
            callbackDelayMaxMs: $this->callbackDelayMaxMs,
            callbackRetry: $this->callbackRetry,
            callbackBackoffMs: $this->callbackBackoffMs,
            callbackTimeoutMs: $this->callbackTimeoutMs,
            callbackSigningEnabled: $this->callbackSigningEnabled,
            callbackSigningSecret: $this->callbackSigningSecret,
            callbackSignatureHeader: $this->callbackSignatureHeader,
            callbackSecretSet: $this->callbackSecretSet,
        );
    }
}
