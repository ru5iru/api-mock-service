<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\EditsResponseSelection;
use App\Models\CallbackAttempt;
use App\Models\MockEndpoint;
use App\Services\Callbacks\CallbackDelivery;
use App\Services\Callbacks\CallbackDispatcher;
use App\Services\Environments\EnvironmentContext;
use App\Services\Response\SelectionConfigurationValidator;
use App\Services\Revisions\RevisionManager;
use App\Services\Templates\FakerMethodCatalog;
use App\Services\Templates\ResponseTemplateEngine;
use App\Services\Templates\TemplateCompiler;
use App\Services\Templates\TemplateContext;
use App\Services\Templates\TemplateRenderException;
use App\Services\Templates\TemplateSchemaConverter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

final class ResponseManager extends Component
{
    use EditsResponseSelection;

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

    public function mount(MockEndpoint $endpoint): void
    {
        $schemas = app(TemplateSchemaConverter::class);
        $this->endpointId = $endpoint->id;
        $this->loadSelection();
        $this->builderSchema = $schemas->emptySchema();
        $this->template = $schemas->schemaToTemplate($this->builderSchema);
        $this->updatedCallbackBody();
        $this->savedFormFingerprint = $this->formFingerprint();
    }

    public function edit(int $responseId): void
    {
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
        $this->savedFormFingerprint = $this->formFingerprint();
    }

    #[On('save-response-drafts')]
    public function savePendingDrafts(int $endpointId): void
    {
        if ($endpointId !== $this->endpointId) {
            return;
        }
        $selectionDraft = $this->selectionDraft();
        $selectionBaseline = $this->savedSelectionFingerprint;
        $formChanged = $this->formFingerprint() !== $this->savedFormFingerprint;
        $this->resetValidation();
        $this->savingDrafts = true;
        try {
            DB::transaction(function () use ($formChanged): void {
                if ($this->selectionFingerprint() !== $this->savedSelectionFingerprint) {
                    $this->saveSelection();
                }
                if ($formChanged) {
                    $this->save();
                    if ($this->getErrorBag()->isNotEmpty()) {
                        throw ValidationException::withMessages($this->getErrorBag()->getMessages());
                    }
                }
            }, 3);
        } catch (ValidationException $exception) {
            foreach ($selectionDraft as $key => $value) {
                $this->{$key} = $value;
            }
            $this->savedSelectionFingerprint = $selectionBaseline;
            $this->setErrorBag($exception->errors());
            $this->dispatch('response-drafts-save-failed', endpointId: $this->endpointId)->to(EndpointForm::class);

            return;
        } finally {
            $this->savingDrafts = false;
        }
        $this->dispatch('response-drafts-saved', endpointId: $this->endpointId)->to(EndpointForm::class);
    }

    private function formFingerprint(): string
    {
        $fields = ['editingId', 'statusCode', 'headersJson', 'body', 'bodyMode', 'template', 'editorView', 'seedMode', 'seed', 'locale', 'delayMs', 'weight', 'callbackEnabled', 'callbackUrl', 'callbackMethod', 'callbackHeadersJson', 'callbackBody', 'callbackDelayMs', 'callbackDelayMaxMs', 'callbackRetry', 'callbackBackoffMs', 'callbackTimeoutMs', 'callbackSigningEnabled', 'callbackSigningSecret', 'callbackSignatureHeader'];

        return hash('sha256', json_encode(array_map(fn (string $field): mixed => $this->{$field}, $fields), JSON_THROW_ON_ERROR));
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

    public function editCallback(int $responseId): void
    {
        $this->edit($responseId);
        $this->dispatch('open-callback-editor', endpointId: $this->endpointId);
    }

    public function createNew(): void
    {
        $this->resetForm();
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

        if ($this->editingId === $entityId) {
            $this->edit($entityId);
        }
    }

    public function save(): void
    {
        $templates = app(ResponseTemplateEngine::class);
        $validated = $this->validate([
            'statusCode' => ['required', 'integer', 'between:100,599'],
            'headersJson' => ['nullable', 'string', 'max:65535'],
            'body' => ['nullable', 'string'],
            'bodyMode' => ['required', 'in:static,template'],
            'template' => ['nullable', 'string', 'max:262144'],
            'editorView' => ['required', 'in:builder,json'],
            'seedMode' => ['required', 'in:random,fixed,request'],
            'seed' => ['nullable', 'integer'],
            'locale' => ['required', 'in:'.implode(',', config('mock.templates.locales', ['en']))],
            'delayMs' => ['required', 'integer', 'min:0', 'max:'.config('mock.max_delay_ms', 30000)],
            'weight' => ['required', 'integer', 'min:1', 'max:1000000'],
            'callbackEnabled' => ['boolean'],
            'callbackUrl' => ['nullable', 'string', 'max:2048'],
            'callbackMethod' => ['required', 'in:POST,PUT,PATCH,DELETE'],
            'callbackHeadersJson' => ['nullable', 'string', 'max:65535'],
            'callbackBody' => ['nullable', 'string', 'max:262144'],
            'callbackDelayMs' => ['required', 'integer', 'between:0,30000'],
            'callbackDelayMaxMs' => ['nullable', 'integer', 'between:0,30000'],
            'callbackRetry' => ['required', 'integer', 'between:1,5'],
            'callbackBackoffMs' => ['required', 'integer', 'between:0,30000'],
            'callbackTimeoutMs' => ['required', 'integer', 'between:100,10000'],
            'callbackSigningEnabled' => ['boolean'],
            'callbackSigningSecret' => ['nullable', 'string', 'max:4096'],
            'callbackSignatureHeader' => ['required', 'regex:/^[A-Za-z0-9!#$%&*+.^_`|~-]+$/D', 'max:128'],
        ]);

        try {
            $headers = trim($validated['headersJson']) === ''
                ? null
                : json_decode($validated['headersJson'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->addError('headersJson', 'Headers must be a valid JSON object: '.$exception->getMessage());

            return;
        }

        if ($headers !== null && (! is_array($headers) || ! json_decode($validated['headersJson']) instanceof \stdClass)) {
            $this->addError('headersJson', 'Headers must be a JSON object of header names and values.');

            return;
        }

        foreach ($headers ?? [] as $name => $value) {
            $validName = is_string($name)
                && preg_match('/^[A-Za-z0-9!#$%&*+.^_`|~-]+$/', $name) === 1;

            if (! $validName || ! is_scalar($value) || preg_match('/[\r\n]/', (string) $value)) {
                $this->addError('headersJson', 'Header names and values must be single-line scalar values.');

                return;
            }
        }

        if ($validated['seedMode'] === 'fixed' && $validated['seed'] === null) {
            $this->addError('seed', 'Enter an integer seed when Fixed is selected.');

            return;
        }

        if ($validated['bodyMode'] === 'template') {
            $validation = $templates->validate((string) $validated['template'], $validated['locale']);
            $this->templateIssues = $validation->issueArrays();
            if ($validation->hasErrors()) {
                $first = collect($validation->issues)->first(static fn ($issue): bool => $issue->severity === 'error');
                $this->addError('template', $first?->message ?? 'The response template is invalid.');

                return;
            }
        }

        try {
            $callbackHeaders = trim((string) $validated['callbackHeadersJson']) === ''
                ? [] : json_decode($validated['callbackHeadersJson'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->addError('callbackHeadersJson', 'Enter a valid JSON object of callback headers.');

            return;
        }
        if (! is_array($callbackHeaders) || ($callbackHeaders !== [] && array_is_list($callbackHeaders))) {
            $this->addError('callbackHeadersJson', 'Callback headers must be a JSON object.');

            return;
        }
        foreach ($callbackHeaders as $name => $value) {
            if (! is_string($name) || preg_match('/^[A-Za-z0-9!#$%&*+.^_`|~-]+$/D', $name) !== 1
                || ! is_string($value) || preg_match('/[\r\n]/', $value) === 1) {
                $this->addError('callbackHeadersJson', 'Callback headers require single-line string names and values.');

                return;
            }
        }

        if ($validated['callbackEnabled']) {
            if (! is_string($validated['callbackUrl']) || $validated['callbackUrl'] === '') {
                $this->addError('callbackUrl', 'Enter a callback URL before enabling callbacks.');

                return;
            }
            $sampleUrl = preg_replace('/\{\{[^{}]+\}\}|\$request\.[A-Za-z0-9_.-]+/', 'example.test', $validated['callbackUrl']);
            try {
                app(CallbackDelivery::class)->validateUrl($sampleUrl);
            } catch (\InvalidArgumentException) {
                $this->addError('callbackUrl', 'Enter a well-formed HTTP(S) callback URL.');

                return;
            }
            $validation = app(TemplateCompiler::class)->compile((string) ($validated['callbackBody'] ?: '{}'), $validated['locale'], true);
            if ($validation->hasErrors()) {
                $this->addError('callbackBody', $validation->issues[0]->message);

                return;
            }
        }
        if ($validated['callbackDelayMaxMs'] !== null && $validated['callbackDelayMaxMs'] < $validated['callbackDelayMs']) {
            $this->addError('callbackDelayMaxMs', 'Maximum delay cannot be less than minimum delay.');

            return;
        }
        $newSigningSecret = (string) ($validated['callbackSigningSecret'] ?? '');
        if ($validated['callbackSigningEnabled'] && $newSigningSecret === '' && ! $this->callbackSecretSet) {
            $this->addError('callbackSigningSecret', 'Enter a signing secret before enabling signatures.');

            return;
        }

        $attributes = [
            'status_code' => $validated['statusCode'],
            'headers' => $headers,
            'body' => $validated['body'],
            'body_mode' => $validated['bodyMode'],
            'template' => $validated['template'],
            'editor_view' => $validated['editorView'],
            'seed_mode' => $validated['seedMode'],
            'seed' => $validated['seedMode'] === 'fixed' ? $validated['seed'] : null,
            'locale' => $validated['locale'],
            'delay_ms' => $validated['delayMs'],
            'weight' => $validated['weight'],
            'callback_enabled' => $validated['callbackEnabled'],
            'callback_url' => $validated['callbackUrl'],
            'callback_method' => $validated['callbackMethod'],
            'callback_headers' => $callbackHeaders,
            'callback_body' => $validated['callbackBody'],
            'callback_delay_ms' => $validated['callbackDelayMs'],
            'callback_delay_max_ms' => $validated['callbackDelayMaxMs'],
            'callback_retry' => $validated['callbackRetry'],
            'callback_backoff_ms' => $validated['callbackBackoffMs'],
            'callback_timeout_ms' => $validated['callbackTimeoutMs'],
            'callback_signing_enabled' => $validated['callbackSigningEnabled'],
            'callback_signature_header' => $validated['callbackSignatureHeader'],
        ];
        if ($newSigningSecret !== '') {
            $attributes['callback_signing_secret'] = $newSigningSecret;
        }

        DB::transaction(function () use ($attributes): void {
            $endpoint = $this->endpoint()->newQuery()->lockForUpdate()->findOrFail($this->endpointId);
            $pool = $endpoint->responses()->with('rules')->get();
            $selectionRows = $pool->map(fn ($item) => array_merge($item->toArray(), ['response_rules' => $item->rules->toArray()]))->all();
            if ($this->editingId === null) {
                $selectionRows[] = ['sequence_order' => $endpoint->selection_mode === 'sequence' ? $pool->count() : null, 'is_default' => false, 'response_rules' => []];
            }
            app(SelectionConfigurationValidator::class)->validate($endpoint->toArray(), $selectionRows);
            if ($this->editingId === null) {
                $newAttributes = $attributes;
                if ($endpoint->selection_mode === 'sequence') {
                    $newAttributes['sequence_order'] = $pool->count();
                }
                $endpoint->responses()->create($newAttributes);

                return;
            }

            $response = $this->endpoint()->responses()->findOrFail($this->editingId);
            $revisions = app(RevisionManager::class);
            $before = $revisions->snapshot($response);
            $response->update($attributes);
            $revisions->recordIfChanged($response, $before);
        }, 3);

        session()->flash('response-status', $this->editingId === null ? 'Response added.' : 'Response updated.');
        $this->dispatch('toast', message: $this->editingId === null ? 'Response added.' : 'Response updated.');
        $this->resetForm();
        $this->loadSelection();
    }

    public function delete(int $responseId): void
    {
        DB::transaction(function () use ($responseId): void {
            $endpoint = $this->endpoint()->newQuery()->lockForUpdate()->findOrFail($this->endpointId);
            $response = $endpoint->responses()->findOrFail($responseId);
            if ($endpoint->selection_mode === 'rule' && $response->is_default) {
                throw ValidationException::withMessages([
                    'selection_mode' => 'Deleting the fallback would leave rule mode invalid. Select and save another fallback first.',
                ]);
            }
            $response->delete();
            if ($endpoint->selection_mode === 'sequence') {
                $revisions = app(RevisionManager::class);
                foreach ($endpoint->responses()->reorder()->orderBy('sequence_order')->orderBy('id')->get() as $position => $item) {
                    $before = $revisions->snapshot($item);
                    $item->update(['sequence_order' => $position]);
                    $revisions->recordIfChanged($item, $before);
                }
            }
        }, 3);
        if ($this->editingId === $responseId) {
            $this->resetForm();
        }
        $this->loadSelection();
        session()->flash('response-status', 'Response deleted.');
        $this->dispatch('toast', message: 'Response deleted.');
    }

    public function previewCallback(): void
    {
        $this->callbackPreview = '';
        try {
            $response = $this->editingId === null
                ? $this->endpoint()->responses()->make(['locale' => $this->locale, 'seed_mode' => $this->seedMode, 'seed' => $this->seed])
                : $this->endpoint()->responses()->findOrFail($this->editingId);
            $context = app(TemplateContext::class)->preview($this->callbackBody);
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
        app(CallbackDispatcher::class)->enqueue($this->editingId, $id, app(EnvironmentContext::class)->active()->id, app(TemplateContext::class)->synthetic($id));
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
            $result = $templates->preview($this->template, $this->locale, $this->seedMode, $this->seed);
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
            'selectionEnvironment' => $environment,
            'sequencePosition' => $position,
            'nextSequenceResponse' => $next,
            'rulePreview' => $responses->filter(fn ($item) => $item->id !== $this->fallbackResponseId && ($this->responseRules[$item->id] ?? []) !== [])
                ->sortBy(fn ($item) => collect($this->responseRules[$item->id])->min('priority'))->values(),
            'fakerCatalog' => app(FakerMethodCatalog::class)->catalog(),
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
        $this->savedFormFingerprint = $this->formFingerprint();
    }

    private function clearPreview(): void
    {
        $this->previewOutput = '';
        $this->previewBytes = 0;
        $this->previewRenderMs = 0;
    }
}
