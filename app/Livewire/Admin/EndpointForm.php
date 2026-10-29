<?php

namespace App\Livewire\Admin;

use App\Livewire\Admin\Concerns\CopiesMockCurl;
use App\Models\Collection;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\Tag;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Curl\ParsedCurl;
use App\Services\Matching\PathPattern;
use App\Services\Mcp\McpAuditRedactor;
use App\Services\Mutations\EndpointConfigurationValidator;
use App\Services\Mutations\EndpointInput;
use App\Services\Mutations\EndpointMutationService;
use App\Services\Mutations\EndpointRequestService;
use App\Services\Response\SelectionConfigurationValidator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

final class EndpointForm extends Component
{
    use CopiesMockCurl;

    private const EXAMPLE_CURL = EndpointRequestService::EXAMPLE_CURL;

    private ?array $importVariableProvenance = null;

    #[Locked]
    public array $requestBaseline = [];

    public ?int $endpointId = null;

    public string $name = '';

    public bool $enabled = true;

    public int $priority = 0;

    public string $rawCurl = '';

    public bool $curlExpanded = false;

    public bool $excludeCookies = false;

    public bool $excludeAuth = true;

    public bool $excludeHeaders = false;

    /** Subordinate choices before the temporary all-header override. */
    #[Locked]
    public ?array $headerChoicesBeforeOverride = null;

    /** @var list<string> */
    public array $excludedQueryParams = [];

    /** @var list<string> */
    public array $excludedHeaderNames = [];

    public bool $pathPatternEnabled = false;

    /** @var array<int, string> */
    public array $pathParameterNames = [];

    /** Original literal values let a converted chip return to its literal within this edit session. */
    public array $pathLiteralValues = [];

    public bool $showExclusionHint = true;

    public string $collectionId = '';

    public string $newCollectionName = '';

    /** @var list<int> */
    public array $tagIds = [];

    public string $newTagName = '';

    /** @var array<int, string> */
    public array $environmentOverrides = [];

    /** @var list<string> */
    public array $touchedSections = [];

    public bool $submitAttempted = false;

    #[Url(as: 'tab', except: 'request')]
    public string $activeTab = 'request';

    public function renameEndpoint(string $value): void
    {
        $this->name = app(EndpointMutationService::class)->rename($this->endpointId, $value);
        if ($this->endpointId !== null) {
            $this->requestBaseline['name'] = $this->name;
        }
        $this->resetValidation('name');
    }

    #[Locked]
    public bool $savingAll = false;

    #[Locked]
    public array $saveAttempt = [];

    #[Locked]
    public string $saveSummary = '';

    #[Locked]
    public array $saveScopes = [];

    public const PREFLIGHT_FAILED = 'Changes were not saved. Correct the highlighted request settings and try again.';

    public const RESPONSES_FAILED = 'Response changes were not saved. Correct the highlighted fields and try again.';

    public const ENDPOINT_FAILED = 'Some changes were saved. Request settings were not saved. Correct the highlighted fields and try again.';

    public const RESPONSES_ERROR = 'Changes were not saved because of an unexpected error. Your edits are still on this page; try again.';

    public const ENDPOINT_ERROR = 'Some changes were saved. Request settings were not saved because of an unexpected error. Your edits are still on this page; try again.';

    public const UNCONFIRMED = 'Save result could not be confirmed. Check the saved configuration before trying again.';

    #[Locked]
    public ?bool $draftResponseReady = null;

    #[Locked]
    public ?bool $draftCallbackReady = null;

    #[On('callback-draft-validity')]
    public function updateCallbackValidity(int $endpointId, bool $valid): void
    {
        if ($endpointId === $this->endpointId) {
            $this->draftCallbackReady = $valid;
        }
    }

    #[On('show-editor-tab')]
    public function showEditorTab(string $tab): void
    {
        if ($tab === 'editor') {
            $tab = 'request';
        }
        if (in_array($tab, ['request', 'matching', 'response', 'callback', 'settings'], true)) {
            $this->activeTab = $tab;
        }
    }

    #[On('response-selection-validity')]
    public function updateResponseValidity(int $endpointId, bool $valid): void
    {
        if ($endpointId === $this->endpointId) {
            $this->draftResponseReady = $valid;
            $this->touchSection('response');
        }
    }

    public function responseSectionReady(): bool
    {
        if ($this->draftResponseReady !== null) {
            return $this->draftResponseReady;
        }
        $endpoint = $this->endpointId ? MockEndpoint::query()->with('responses.rules')->find($this->endpointId) : null;
        if (! $endpoint || $endpoint->responses->isEmpty()) {
            return false;
        }
        try {
            app(SelectionConfigurationValidator::class)->validate($endpoint->toArray(), $endpoint->responses->toArray());

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function requestMetadataReady(): bool
    {
        return app(EndpointConfigurationValidator::class)->metadataReady($this->mutationInput());
    }

    public function saveAll(CurlParser $parser, CurlHasher $hasher): mixed
    {
        if ($this->endpointId === null) {
            return $this->save($parser, $hasher);
        }
        if ($this->savingAll) {
            return null;
        }
        $input = $this->mutationInput();
        $this->saveAttempt = ['id' => (string) Str::uuid(), 'input' => $input->values()];
        $this->saveScopes = $this->parentSaveScopes();
        $this->saveSummary = '';
        $this->submitAttempted = true;
        $this->resetValidation();
        try {
            app(EndpointConfigurationValidator::class)->validate($input, $this->endpointId);
        } catch (ValidationException $exception) {
            $this->endpointValidationFailed($exception);
            $this->saveSummary = self::PREFLIGHT_FAILED;
            $this->saveScopes['request']['state'] = 'Needs attention';
            $this->dispatch('inspect-response-drafts', endpointId: $this->endpointId, attemptId: $this->saveAttempt['id'])->to(ResponseManager::class);

            return null;
        }
        $this->savingAll = true;
        $this->dispatch('save-response-drafts', endpointId: $this->endpointId, attemptId: $this->saveAttempt['id'])->to(ResponseManager::class);

        return null;
    }

    #[On('response-drafts-saved')]
    public function finishSavingAll(int $endpointId, CurlParser $parser, CurlHasher $hasher, array $scopes = [], string $attemptId = ''): mixed
    {
        if ($endpointId !== $this->endpointId || ! $this->savingAll) {
            return null;
        }
        if ($attemptId !== '' && $attemptId !== ($this->saveAttempt['id'] ?? null)) {
            return null;
        }
        $this->saveScopes = array_replace($this->saveScopes, $scopes);
        try {
            return $this->persistEndpoint(new EndpointInput(...$this->saveAttempt['input']), true);
        } finally {
            $this->savingAll = false;
        }
    }

    #[On('response-drafts-save-failed')]
    public function responseDraftsFailed(int $endpointId, array $scopes = [], string $attemptId = '', bool $unexpected = false): void
    {
        if ($endpointId === $this->endpointId && ($attemptId === '' || $attemptId === ($this->saveAttempt['id'] ?? null))) {
            $this->savingAll = false;
            $this->saveScopes = array_replace($this->saveScopes, $scopes);
            $this->saveSummary = $unexpected ? self::RESPONSES_ERROR : self::RESPONSES_FAILED;
        }
    }

    #[On('response-drafts-status')]
    public function responseDraftStatus(int $endpointId, string $attemptId, array $scopes): void
    {
        if ($endpointId !== $this->endpointId || $attemptId !== ($this->saveAttempt['id'] ?? null)) {
            return;
        }
        $this->saveScopes = array_filter($this->saveScopes, fn ($scope) => ($scope['owner'] ?? '') === 'request');
        $this->saveScopes = array_replace($this->saveScopes, $scopes);
    }

    public function saveResultUnconfirmed(): void
    {
        if ($this->endpointId === null) {
            return;
        }
        // A client failure is not evidence that either transaction rolled back.
        $this->savingAll = false;
        $this->saveSummary = self::UNCONFIRMED;
        if ($this->saveAttempt === []) {
            $this->saveAttempt = ['id' => (string) Str::uuid(), 'input' => $this->mutationInput()->values()];
            $this->saveScopes = $this->parentSaveScopes();
        }
        foreach ($this->saveScopes as &$scope) {
            if ($scope['state'] !== 'No changes') {
                $scope['state'] = 'Needs attention';
            }
        }
        unset($scope);
        $this->dispatch('inspect-response-drafts', endpointId: $this->endpointId, attemptId: $this->saveAttempt['id'], uncertain: true)->to(ResponseManager::class);
    }

    public function updated(string $property): void
    {
        if ($this->savingAll) {
            return;
        }
        foreach ($this->saveScopes as $key => $scope) {
            if (($scope['owner'] ?? '') === 'request' && in_array(explode('.', $property)[0], $scope['fields'], true)) {
                unset($this->saveScopes[$key]);
            }
        }
    }

    private function endpointValidationFailed(ValidationException $exception): void
    {
        $this->setErrorBag($exception->errors());
        if (array_key_exists('rawCurl', $exception->errors())) {
            $this->curlExpanded = true;
        }
        $firstField = explode('.', (string) array_key_first($exception->errors()))[0];
        $this->activeTab = in_array($firstField, ['excludedQueryParams', 'excludedHeaderNames', 'pathPatternEnabled'], true)
            ? 'matching'
            : (in_array($firstField, ['name', 'enabled', 'priority', 'collectionId', 'tagIds', 'environmentOverrides'], true) ? 'settings' : 'request');
    }

    private function parentSaveScopes(): array
    {
        $groups = [
            'request' => ['Request and matching', ['name', 'enabled', 'priority', 'rawCurl', 'excludeCookies', 'excludeAuth', 'excludeHeaders', 'excludedQueryParams', 'excludedHeaderNames', 'pathPatternEnabled']],
            'organization' => ['Organization and Environment overrides', ['collectionId', 'tagIds', 'environmentOverrides']],
        ];
        $values = $this->mutationInput()->values();
        $scopes = [];
        foreach ($groups as $key => [$label, $fields]) {
            $current = array_intersect_key($values, array_flip($fields));
            $baseline = array_intersect_key($this->requestBaseline, array_flip($fields));
            $scopes[$key] = ['label' => $label, 'state' => $current === $baseline ? 'No changes' : 'Not saved', 'owner' => 'request', 'fields' => $fields, 'fingerprint' => hash('sha256', json_encode($current))];
        }

        return $scopes;
    }

    public function visibleSaveScopes(): array
    {
        $current = $this->parentSaveScopes();

        return array_filter($this->saveScopes, fn ($scope, $key) => ($scope['owner'] ?? '') !== 'request'
            || ($scope['fingerprint'] ?? '') === ($current[$key]['fingerprint'] ?? null), ARRAY_FILTER_USE_BOTH);
    }

    private function responsesWereSaved(): bool
    {
        return collect($this->saveScopes)->contains(fn ($scope) => ($scope['owner'] ?? '') === 'responses' && $scope['state'] === 'Saved');
    }

    public function mount(?MockEndpoint $endpoint = null): void
    {
        if ($this->activeTab === 'editor') {
            $this->activeTab = 'request';
        }
        $this->showExclusionHint = ! DB::table('app_settings')->where('key', 'field_exclusion_hint_seen')->exists();
        if (! in_array($this->activeTab, ['request', 'matching', 'response', 'callback', 'settings'], true)) {
            $this->activeTab = 'request';
        }
        if ($endpoint === null) {
            $prefill = request()->query('curl');
            if (is_string($prefill) && strlen($prefill) <= 1048576) {
                $this->rawCurl = $prefill;
            }

            $this->requestBaseline = $this->requestDraftValues();

            return;
        }

        $this->endpointId = $endpoint->id;
        $this->name = (string) $endpoint->name;
        $this->enabled = $endpoint->enabled;
        $this->priority = $endpoint->priority;
        $this->rawCurl = $endpoint->raw_curl;
        $this->excludeCookies = $endpoint->exclude_cookies;
        $this->excludeAuth = $endpoint->exclude_auth;
        $this->excludeHeaders = $endpoint->exclude_headers;
        $this->excludedQueryParams = $endpoint->excluded_query_params ?? [];
        $this->excludedHeaderNames = $endpoint->excluded_headers ?? [];
        $this->pathPatternEnabled = (bool) $endpoint->path_pattern_enabled;
        if ($this->showExclusionHint && ($this->excludedQueryParams !== [] || $this->excludedHeaderNames !== [])) {
            $this->dismissExclusionHint();
        }
        $this->syncPathParameters();
        $this->collectionId = $endpoint->collection_id === null ? '' : (string) $endpoint->collection_id;
        $this->tagIds = $endpoint->tags()->pluck('tags.id')->map(static fn ($id): int => (int) $id)->all();
        $this->environmentOverrides = $endpoint->environmentOverrides()
            ->get()
            ->mapWithKeys(static fn ($environment): array => [$environment->id => $environment->pivot->enabled ? '1' : '0'])
            ->all();
        $this->requestBaseline = $this->requestDraftValues();
    }

    public function requestDraftValues(): array
    {
        $fields = ['name', 'enabled', 'priority', 'rawCurl', 'excludeCookies', 'excludeAuth', 'excludeHeaders',
            'excludedQueryParams', 'excludedHeaderNames', 'pathPatternEnabled', 'pathParameterNames',
            'collectionId', 'tagIds', 'environmentOverrides', 'newCollectionName', 'newTagName'];

        return array_combine($fields, array_map(fn ($field) => $this->{$field}, $fields));
    }

    public function updatedExcludeHeaders(bool $value): void
    {
        $this->touchSection('matching');
        if ($value) {
            $this->headerChoicesBeforeOverride ??= [$this->excludeCookies, $this->excludeAuth];
            $this->excludeCookies = true;
            $this->excludeAuth = true;
        } elseif ($this->headerChoicesBeforeOverride !== null) {
            [$this->excludeCookies, $this->excludeAuth] = $this->headerChoicesBeforeOverride;
            $this->headerChoicesBeforeOverride = null;
        }
    }

    public function updatedExcludeCookies(): void
    {
        $this->touchSection('matching');
    }

    public function updatedExcludeAuth(): void
    {
        $this->touchSection('matching');
    }

    public function updatedRawCurl(): void
    {
        $this->touchSection('request');
        $this->pathLiteralValues = [];
        $this->syncPathParameters();
        $this->notifyPathPreview();
    }

    public function dismissExclusionHint(): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => 'field_exclusion_hint_seen'], [
            'value' => '1', 'updated_at' => now(), 'created_at' => now(),
        ]);
        $this->showExclusionHint = false;
    }

    public function updatedExcludedQueryParams(): void
    {
        $this->touchSection('matching');
        if ($this->excludedQueryParams !== []) {
            $this->dismissExclusionHint();
        }
    }

    public function updatedExcludedHeaderNames(): void
    {
        $this->touchSection('matching');
        if ($this->excludedHeaderNames !== []) {
            $this->dismissExclusionHint();
        }
    }

    public function updatedPathPatternEnabled(): void
    {
        $this->touchSection('request');
        $this->syncPathParameters();
        $this->notifyPathPreview();
    }

    public function togglePathSegment(int $index): void
    {
        if (! $this->pathPatternEnabled) {
            return;
        }
        try {
            $parsed = app(CurlParser::class)->parse($this->rawCurl);
            $segments = explode('/', (string) (parse_url($parsed->url, PHP_URL_PATH) ?: '/'));
            if (! isset($segments[$index]) || $segments[$index] === '') {
                return;
            }
            if (preg_match('/^\{([^{}]+)\}$/D', $segments[$index], $match)) {
                $segments[$index] = $this->pathLiteralValues[$index] ?? 'sample_'.$match[1];
            } else {
                $this->pathLiteralValues[$index] = $segments[$index];
                $name = 'param_'.$index;
                while (in_array($name, $this->pathParameterNames, true)) {
                    $name .= '_';
                }
                $segments[$index] = '{'.$name.'}';
            }
            $this->replaceRequestPath($parsed, implode('/', $segments));
            $this->syncPathParameters();
            $this->notifyPathPreview();
        } catch (InvalidArgumentException $exception) {
            $this->addError('pathPatternEnabled', $exception->getMessage());
        }
    }

    public function updatedPathParameterNames(string $value, string $index): void
    {
        if (! $this->pathPatternEnabled || ! ctype_digit($index)) {
            return;
        }
        try {
            $parsed = app(CurlParser::class)->parse($this->rawCurl);
            $segments = explode('/', (string) (parse_url($parsed->url, PHP_URL_PATH) ?: '/'));
            if (! isset($segments[(int) $index]) || ! str_starts_with($segments[(int) $index], '{')) {
                return;
            }
            $segments[(int) $index] = '{'.$value.'}';
            $path = implode('/', $segments);
            // Keep incomplete names visible while typing; canonical validation blocks save.
            $this->replaceRequestPath($parsed, $path);
            $this->notifyPathPreview();
        } catch (InvalidArgumentException $exception) {
            $this->addError('pathPatternEnabled', $exception->getMessage());
        }
    }

    private function syncPathParameters(): void
    {
        $this->pathParameterNames = [];
        try {
            $parsed = app(CurlParser::class)->parse($this->rawCurl);
            foreach (explode('/', (string) (parse_url($parsed->url, PHP_URL_PATH) ?: '/')) as $index => $segment) {
                if (preg_match('/^\{([^{}]*)\}$/D', $segment, $match)) {
                    $this->pathParameterNames[$index] = $match[1];
                }
            }
        } catch (InvalidArgumentException) {
            // Invalid pasted curls remain in the existing parse-error flow.
        }
    }

    private function replaceRequestPath(ParsedCurl $parsed, string $path): void
    {
        $url = app(PathPattern::class)->replaceUrlPath($parsed->url, $path);
        $quote = static fn (string $value): string => "'".str_replace("'", "'\\''", $value)."'";
        $parts = ['curl --request '.$quote($parsed->method).' '.$quote($url)];
        foreach ($parsed->headers as $header) {
            $parts[] = '--header '.$quote($header['name'].': '.$header['value']);
        }
        if ($parsed->body !== '') {
            $parts[] = '--data-raw '.$quote($parsed->body);
        }
        $this->rawCurl = implode(' ', $parts);
        $this->touchSection('request');
    }

    private function notifyPathPreview(): void
    {
        try {
            $parsed = app(CurlParser::class)->parse($this->rawCurl);
            $path = (string) (parse_url($parsed->url, PHP_URL_PATH) ?: '/');
            if ($this->pathPatternEnabled) {
                app(PathPattern::class)->validate($path);
            }
            $this->dispatch('endpoint-path-preview', endpointId: $this->endpointId, path: $path, enabled: $this->pathPatternEnabled);
        } catch (InvalidArgumentException) {
            // Keep the last valid synthetic preview until the path is valid again.
        }
    }

    private function fieldMatching(?string $url = null): array
    {
        return app(EndpointRequestService::class)->fieldMatching($this->mutationInput(), $url);
    }

    public function touchSection(string $section): void
    {
        if (in_array($section, ['request', 'matching', 'response', 'callback', 'settings'], true)
            && ! in_array($section, $this->touchedSections, true)) {
            $this->touchedSections[] = $section;
        }
    }

    public function loadExample(): void
    {
        $this->touchSection('request');
        $this->rawCurl = self::EXAMPLE_CURL;
        $this->resetValidation('rawCurl');
    }

    public function clearCurl(): void
    {
        $this->touchSection('request');
        $this->rawCurl = '';
        $this->curlExpanded = false;
        $this->resetValidation('rawCurl');
    }

    #[On('revision-restored')]
    public function revisionRestored(string $entityType, int $entityId): mixed
    {
        if ($entityType !== 'endpoint' || $entityId !== $this->endpointId) {
            return null;
        }

        return $this->redirect(route('dashboard.endpoints.edit', ['endpoint' => $entityId]), navigate: true);
    }

    public function createCollectionInline(): void
    {
        $this->validateOnly('newCollectionName', ['newCollectionName' => ['required', 'string', 'max:255']]);
        $collection = Collection::query()->create(['name' => trim($this->newCollectionName)]);
        $this->collectionId = (string) $collection->id;
        $this->newCollectionName = '';
        $this->dispatch('toast', message: 'Collection created and selected.');
    }

    public function createTagInline(): void
    {
        $this->validateOnly('newTagName', ['newTagName' => ['required', 'string', 'max:80']]);
        $normalized = Str::lower(trim($this->newTagName));
        $tag = Tag::query()->where('normalized_name', $normalized)->first();
        $tag ??= Tag::query()->create(['name' => trim($this->newTagName)]);
        $this->tagIds = array_values(array_unique([...$this->tagIds, $tag->id]));
        $this->newTagName = '';
        $this->dispatch('toast', message: 'Tag selected. Save changes to assign it to this endpoint.');
    }

    public function maskSecrets(): void
    {
        $this->touchSection('request');
        $this->rawCurl = app(EndpointRequestService::class)->maskSecrets($this->rawCurl);
    }

    public function save(CurlParser $parser, CurlHasher $hasher): mixed
    {
        return $this->persistEndpoint($this->mutationInput());
    }

    private function persistEndpoint(EndpointInput $input, bool $global = false): mixed
    {
        $this->submitAttempted = true;
        $created = $this->endpointId === null;
        $this->resetValidation();
        try {
            $endpoint = app(EndpointMutationService::class)->save($input, $this->endpointId);
        } catch (ValidationException $exception) {
            $this->endpointValidationFailed($exception);
            if ($global) {
                $this->saveSummary = $this->responsesWereSaved() ? self::ENDPOINT_FAILED : self::PREFLIGHT_FAILED;
                $this->saveScopes['request']['state'] = 'Needs attention';
            }

            return null;
        } catch (\Throwable $exception) {
            if (! $global) {
                throw $exception;
            }
            // Treat exception messages as sensitive metadata, never log SQL/bindings.
            Log::error('Dashboard endpoint save failed', app(McpAuditRedactor::class)->redact(['exception_class' => $exception::class, 'error_message' => '[REDACTED]']));
            $this->saveSummary = $this->responsesWereSaved() ? self::ENDPOINT_ERROR : self::RESPONSES_ERROR;
            $this->saveScopes['request']['state'] = 'Needs attention';

            return null;
        }

        if ($global && $this->mutationInput()->values() !== $input->values()) {
            foreach ($input->values() as $field => $value) {
                $this->requestBaseline[$field] = $value;
            }
            foreach (['request', 'organization'] as $key) {
                if ($this->saveScopes[$key]['state'] !== 'No changes') {
                    $this->saveScopes[$key]['state'] = 'Saved';
                }
            }
            $this->saveSummary = 'Endpoint changes saved.';

            return null;
        }

        session()->flash(
            'status',
            $created
                ? 'Endpoint created – add a response so it can answer requests.'
                : 'Endpoint changes saved.',
        );

        $destination = route('dashboard.endpoints.edit', ['endpoint' => $endpoint->id]);

        return $this->redirect($created ? $destination.'?tab=response' : $destination.($this->activeTab === 'request' ? '' : '?tab='.$this->activeTab), navigate: true);
    }

    public function render(): View
    {
        $parser = app(CurlParser::class);
        $hasher = app(CurlHasher::class);
        $preview = null;
        $previewError = null;
        $duplicate = null;
        $headerAnalysis = [];
        $queryParameters = [];
        $prettyBody = '';
        $displayCanonical = '';
        $pathSegments = [];

        if (trim($this->rawCurl) !== '') {
            try {
                ['parsed' => $parsed, 'variant' => $variant] = app(EndpointRequestService::class)->prepare($this->mutationInput());
                $pathSegments = explode('/', (string) (parse_url($parsed->url, PHP_URL_PATH) ?: '/'));
                $preview = compact('parsed', 'variant');
                $duplicate = MockEndpoint::query()
                    ->where('curl_hash', $variant->hash)
                    ->when($this->endpointId !== null, fn ($query) => $query->where('id', '!=', $this->endpointId))
                    ->first();
                $headerAnalysis = $this->analyzeHeaders($parsed);
                $queryParameters = $this->queryParameters($parsed);
                $prettyBody = $this->prettyBody($parsed->body);
                $displayCanonical = $this->maskCanonical($variant->normalized, $headerAnalysis);
            } catch (InvalidArgumentException|ValidationException $exception) {
                $previewError = $exception->getMessage();
            }
        }

        return view('livewire.admin.endpoint-form', [
            'preview' => $preview,
            'pathSegments' => $pathSegments,
            'previewError' => $previewError,
            'duplicate' => $duplicate,
            'derivedName' => $preview ? $this->deriveName($preview['parsed']) : 'METHOD /path',
            'headerAnalysis' => $headerAnalysis,
            'queryParameters' => $queryParameters,
            'prettyBody' => $prettyBody,
            'displayCanonical' => $displayCanonical,
            'curlWarnings' => $this->curlWarnings(),
            'containsSecrets' => collect($headerAnalysis)->contains('sensitive', true) || collect($queryParameters)->contains('sensitive', true),
            'isExample' => trim($this->rawCurl) === trim(self::EXAMPLE_CURL),
            'collections' => Collection::query()->orderBy('name')->get(),
            'availableTags' => Tag::query()->orderBy('name')->get(),
            'environments' => Environment::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'endpoint' => $this->endpointId ? MockEndpoint::query()->findOrFail($this->endpointId) : null,
        ]);
    }

    private function deriveName(ParsedCurl $parsed): string
    {
        return app(EndpointRequestService::class)->deriveName($parsed);
    }

    /** @return list<array{name: string, value: string, display_value: string, sensitive: bool, excluded_reason: ?string}> */
    private function analyzeHeaders(ParsedCurl $parsed): array
    {
        return app(EndpointRequestService::class)->analyzeHeaders($this->mutationInput(), $parsed, $this->variableProvenance());
    }

    /** @return list<array{key: string, value: string, display_value: string, sensitive: bool}> */
    private function variableProvenance(): array
    {
        return $this->importVariableProvenance ??= ($this->endpointId ? MockEndpoint::find($this->endpointId)?->external_source['variable_provenance'] ?? [] : []);
    }

    private function queryParameters(ParsedCurl $parsed): array
    {
        return app(EndpointRequestService::class)->queryParameters($this->mutationInput(), $parsed, $this->variableProvenance());
    }

    /** @param list<array{name: string, value: string, display_value: string, sensitive: bool, excluded_reason: ?string}> $headers */
    private function maskCanonical(string $canonical, array $headers): string
    {
        return app(EndpointRequestService::class)->maskCanonical($canonical, $headers);
    }

    private function prettyBody(string $body): string
    {
        return app(EndpointRequestService::class)->prettyBody($body);
    }

    /** @return list<string> */
    private function curlWarnings(): array
    {
        return app(EndpointRequestService::class)->curlWarnings($this->rawCurl);
    }

    private function mutationInput(): EndpointInput
    {
        return new EndpointInput(
            name: $this->name,
            enabled: $this->enabled,
            priority: $this->priority,
            rawCurl: $this->rawCurl,
            excludeCookies: $this->excludeCookies,
            excludeAuth: $this->excludeAuth,
            excludeHeaders: $this->excludeHeaders,
            excludedQueryParams: $this->excludedQueryParams,
            excludedHeaderNames: $this->excludedHeaderNames,
            pathPatternEnabled: $this->pathPatternEnabled,
            collectionId: $this->collectionId,
            tagIds: $this->tagIds,
            environmentOverrides: $this->environmentOverrides,
        );
    }
}
