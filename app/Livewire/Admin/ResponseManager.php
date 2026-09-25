<?php

namespace App\Livewire\Admin;

use App\Models\MockEndpoint;
use App\Services\Revisions\RevisionManager;
use App\Services\Templates\FakerMethodCatalog;
use App\Services\Templates\ResponseTemplateEngine;
use App\Services\Templates\TemplateRenderException;
use App\Services\Templates\TemplateSchemaConverter;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use JsonException;
use Livewire\Attributes\On;
use Livewire\Component;

final class ResponseManager extends Component
{
    public int $endpointId;

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

    public function mount(MockEndpoint $endpoint): void
    {
        $schemas = app(TemplateSchemaConverter::class);
        $this->endpointId = $endpoint->id;
        $this->builderSchema = $schemas->emptySchema();
        $this->template = $schemas->schemaToTemplate($this->builderSchema);
    }

    public function edit(int $responseId): void
    {
        $schemas = app(TemplateSchemaConverter::class);
        $templates = app(ResponseTemplateEngine::class);
        $response = $this->endpoint()->responses()->findOrFail($responseId);
        $this->editingId = $response->id;
        $this->statusCode = $response->status_code;
        $this->headersJson = json_encode($response->headers ?? new \stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
        $this->resetValidation();
    }

    public function createNew(): void
    {
        $this->resetForm();
    }

    #[On('revision-restored')]
    public function revisionRestored(string $entityType, int $entityId): void
    {
        if ($entityType !== 'response') {
            return;
        }

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
        ]);

        try {
            $headers = trim($validated['headersJson']) === ''
                ? null
                : json_decode($validated['headersJson'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->addError('headersJson', 'Headers must be a valid JSON object: '.$exception->getMessage());

            return;
        }

        if ($headers !== null && (! is_array($headers) || array_is_list($headers))) {
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
        ];

        DB::transaction(function () use ($attributes): void {
            if ($this->editingId === null) {
                $this->endpoint()->responses()->create($attributes);

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
    }

    public function delete(int $responseId): void
    {
        $this->endpoint()->responses()->findOrFail($responseId)->delete();
        if ($this->editingId === $responseId) {
            $this->resetForm();
        }
        session()->flash('response-status', 'Response deleted.');
        $this->dispatch('toast', message: 'Response deleted.');
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

    public function addSchemaRow(string $path): void
    {
        $schemas = app(TemplateSchemaConverter::class);
        $rows = data_get($this->builderSchema, $path, []);
        if (! is_array($rows)) {
            return;
        }
        $rows[] = $schemas->emptyRow();
        data_set($this->builderSchema, $path, $rows);
        $this->updatedBuilderSchema();
    }

    public function removeSchemaRow(string $path, int $index): void
    {
        $schemas = app(TemplateSchemaConverter::class);
        $rows = data_get($this->builderSchema, $path, []);
        if (! is_array($rows) || ! array_key_exists($index, $rows)) {
            return;
        }
        array_splice($rows, $index, 1);
        if ($rows === []) {
            $rows[] = $schemas->emptyRow();
        }
        data_set($this->builderSchema, $path, array_values($rows));
        $this->updatedBuilderSchema();
    }

    public function moveSchemaRow(string $path, int $index, int $direction): void
    {
        $rows = data_get($this->builderSchema, $path, []);
        $target = $index + $direction;
        if (! is_array($rows) || ! isset($rows[$index]) || $target < 0 || $target >= count($rows)) {
            return;
        }
        [$rows[$index], $rows[$target]] = [$rows[$target], $rows[$index]];
        data_set($this->builderSchema, $path, array_values($rows));
        $this->updatedBuilderSchema();
    }

    public function reorderSchemaRow(string $path, int $from, int $to): void
    {
        $rows = data_get($this->builderSchema, $path, []);
        if (! is_array($rows) || ! isset($rows[$from]) || ! isset($rows[$to]) || $from === $to) {
            return;
        }

        $row = array_splice($rows, $from, 1)[0];
        array_splice($rows, $to, 0, [$row]);
        data_set($this->builderSchema, $path, array_values($rows));
        $this->updatedBuilderSchema();
    }

    public function setSchemaMethod(string $path, string $method): void
    {
        $definition = app(FakerMethodCatalog::class)->definitions()[$method] ?? null;
        if ($definition === null) {
            return;
        }

        $options = [];
        foreach ($definition['argsHint'] ?? [] as $name => $hint) {
            $options[$name] = $hint['default'] ?? '';
        }
        data_set($this->builderSchema, $path.'.method', $method);
        data_set($this->builderSchema, $path.'.type', 'faker');
        data_set($this->builderSchema, $path.'.args', '');
        data_set($this->builderSchema, $path.'.args_options', $options);
        $this->updatedBuilderSchema();
    }

    public function addObjectChild(string $path): void
    {
        $schemas = app(TemplateSchemaConverter::class);
        $childrenPath = $path.'.children';
        $children = data_get($this->builderSchema, $childrenPath, []);
        $children[] = $schemas->emptyRow();
        data_set($this->builderSchema, $childrenPath, $children);
        $this->updatedBuilderSchema();
    }

    public function initializeArrayItem(string $path): void
    {
        $schemas = app(TemplateSchemaConverter::class);
        if (! is_array(data_get($this->builderSchema, $path.'.item'))) {
            data_set($this->builderSchema, $path.'.item', $schemas->emptyRow());
        }
        $this->updatedBuilderSchema();
    }

    public function render(): View
    {
        return view('livewire.admin.response-manager', [
            'responses' => $this->endpoint()->responses()->get(),
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
        $this->resetValidation();
    }

    private function clearPreview(): void
    {
        $this->previewOutput = '';
        $this->previewBytes = 0;
        $this->previewRenderMs = 0;
    }
}
