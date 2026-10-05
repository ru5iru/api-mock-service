<?php

namespace App\Livewire\Admin;

use App\Models\Collection;
use App\Models\Environment;
use App\Models\MockEndpoint;
use App\Models\Tag;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Curl\ParsedCurl;
use App\Services\Curl\RequestCredentialPolicy;
use App\Services\Matching\FieldMatchingConfiguration;
use App\Services\Matching\PathPattern;
use App\Services\Response\SelectionConfigurationValidator;
use App\Services\Revisions\RevisionManager;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

final class EndpointForm extends Component
{
    private const EXAMPLE_CURL = <<<'CURL'
curl --request POST 'https://api.example.test/v1/items?limit=10' \
  --header 'Content-Type: application/json' \
  --header 'Authorization: Bearer replace-me' \
  --data '{"name":"Example"}'
CURL;

    public ?int $endpointId = null;

    public string $name = '';

    public bool $enabled = true;

    public int $priority = 0;

    public string $rawCurl = '';

    public bool $curlExpanded = false;

    public bool $excludeCookies = false;

    public bool $excludeAuth = true;

    public bool $excludeHeaders = false;

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
        $name = trim($value);
        Validator::make(['name' => $name], ['name' => ['nullable', 'string', 'max:255']])->validate();
        $this->name = $name;
        $this->resetValidation('name');
        if ($this->endpointId !== null) {
            DB::transaction(function () use ($name): void {
                $endpoint = MockEndpoint::query()->lockForUpdate()->findOrFail($this->endpointId);
                $revisions = app(RevisionManager::class);
                $before = $revisions->snapshot($endpoint);
                $endpoint->update(['name' => $name === '' ? null : $name]);
                $revisions->recordIfChanged($endpoint, $before);
            });
        }
    }

    #[Locked]
    public bool $savingAll = false;

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
        if (in_array($tab, ['request', 'matching', 'response', 'callback'], true)) {
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
        return Validator::make([
            'name' => $this->name, 'priority' => $this->priority,
            'collectionId' => $this->collectionId, 'tagIds' => $this->tagIds,
            'environmentOverrides' => $this->environmentOverrides,
        ], [
            'name' => ['nullable', 'string', 'max:255'],
            'priority' => ['required', 'integer', 'between:-1000,1000'],
            'collectionId' => ['nullable', 'integer', 'exists:collections,id'],
            'tagIds' => ['array'], 'tagIds.*' => ['integer', 'distinct', 'exists:tags,id'],
            'environmentOverrides.*' => ['nullable', 'in:,0,1'],
        ])->passes() && Environment::query()->whereKey(array_keys($this->environmentOverrides))->count() === count($this->environmentOverrides);
    }

    public function saveAll(CurlParser $parser, CurlHasher $hasher): mixed
    {
        if ($this->endpointId === null) {
            return $this->save($parser, $hasher);
        }
        if (! $this->savingAll) {
            $this->savingAll = true;
            $this->dispatch('save-response-drafts', endpointId: $this->endpointId)->to(ResponseManager::class);
        }

        return null;
    }

    #[On('response-drafts-saved')]
    public function finishSavingAll(int $endpointId, CurlParser $parser, CurlHasher $hasher): mixed
    {
        if ($endpointId !== $this->endpointId || ! $this->savingAll) {
            return null;
        }
        $this->savingAll = false;

        return $this->save($parser, $hasher);
    }

    #[On('response-drafts-save-failed')]
    public function responseDraftsFailed(int $endpointId): void
    {
        if ($endpointId === $this->endpointId) {
            $this->savingAll = false;
        }
    }

    public function mount(?MockEndpoint $endpoint = null): void
    {
        $this->showExclusionHint = ! DB::table('app_settings')->where('key', 'field_exclusion_hint_seen')->exists();
        if (! in_array($this->activeTab, ['request', 'matching', 'response', 'callback'], true)) {
            $this->activeTab = 'request';
        }
        if ($endpoint === null) {
            $prefill = request()->query('curl');
            if (is_string($prefill) && strlen($prefill) <= 1048576) {
                $this->rawCurl = $prefill;
            }

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
    }

    public function updatedExcludeHeaders(bool $value): void
    {
        $this->touchSection('matching');

        if ($value) {
            $this->excludeCookies = true;
            $this->excludeAuth = true;
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
        return app(FieldMatchingConfiguration::class)->validate([
            'excluded_query_params' => $this->excludedQueryParams,
            'excluded_headers' => $this->excludedHeaderNames,
            'path_pattern_enabled' => $this->pathPatternEnabled,
        ], $url);
    }

    public function touchSection(string $section): void
    {
        if (in_array($section, ['request', 'matching', 'response', 'callback'], true)
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
        $this->rawCurl = app(RequestCredentialPolicy::class)->maskCurl($this->rawCurl);
    }

    public function save(CurlParser $parser, CurlHasher $hasher): mixed
    {
        $this->submitAttempted = true;

        $this->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],
            'priority' => ['required', 'integer', 'between:-1000,1000'],
            'rawCurl' => ['required', 'string', 'max:1048576'],
            'excludeCookies' => ['boolean'],
            'excludeAuth' => ['boolean'],
            'excludeHeaders' => ['boolean'],
            'pathPatternEnabled' => ['boolean'],
            'excludedQueryParams' => ['array', 'max:100'],
            'excludedHeaderNames' => ['array', 'max:100'],
            'collectionId' => ['nullable', 'integer', 'exists:collections,id'],
            'tagIds' => ['array'],
            'tagIds.*' => ['integer', 'distinct', 'exists:tags,id'],
            'environmentOverrides' => ['array'],
            'environmentOverrides.*' => ['nullable', 'in:,0,1'],
        ]);

        $overrideIds = array_values(array_unique(array_map('intval', array_keys($this->environmentOverrides))));
        if (Environment::query()->whereKey($overrideIds)->count() !== count($overrideIds)) {
            $this->addError('environmentOverrides', 'Every override must reference an existing environment.');

            return null;
        }

        if (trim($this->rawCurl) === trim(self::EXAMPLE_CURL)) {
            $this->addError('rawCurl', 'This is the sample curl. Replace its URL and values with the request you want to mock.');

            return null;
        }

        try {
            $parsed = $parser->parse($this->rawCurl);
            $matching = $this->fieldMatching($parsed->url);
            $variant = $hasher->forOptions(
                $parsed,
                $this->excludeHeaders ? false : $this->excludeCookies,
                $this->excludeHeaders ? false : $this->excludeAuth,
                $this->excludeHeaders,
                $matching['excluded_query_params'],
                $matching['excluded_headers'],
                $matching['path_pattern_enabled'],
            );
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $target = str_starts_with($field, 'excluded_query_params') ? 'excludedQueryParams'
                    : (str_starts_with($field, 'excluded_headers') ? 'excludedHeaderNames' : 'pathPatternEnabled');
                $this->addError($target, $messages[0]);
            }

            return null;
        } catch (InvalidArgumentException $exception) {
            $this->addError('rawCurl', $exception->getMessage());

            return null;
        }

        $duplicate = MockEndpoint::query()
            ->where('curl_hash', $variant->hash)
            ->when($this->endpointId !== null, fn ($query) => $query->where('id', '!=', $this->endpointId))
            ->orderBy('id')
            ->first();

        if ($duplicate !== null) {
            $this->addError('rawCurl', 'This signature is already used by '.$duplicate->displayName().'. Open that endpoint or change the matching request.');

            return null;
        }

        $endpoint = $this->endpointId === null
            ? new MockEndpoint
            : MockEndpoint::query()->findOrFail($this->endpointId);
        $created = $this->endpointId === null;
        $displayName = trim($this->name) ?: $this->deriveName($parsed);
        $revisions = app(RevisionManager::class);
        $before = $created ? null : $revisions->snapshot($endpoint);

        DB::transaction(function () use ($endpoint, $displayName, $parsed, $variant, $matching, $revisions, $before): void {
            $endpoint->fill([
                'collection_id' => $this->collectionId === '' ? null : (int) $this->collectionId,
                'name' => $displayName,
                'enabled' => $this->enabled,
                'priority' => $this->priority,
                'method' => $parsed->method,
                'raw_curl' => $this->rawCurl,
                'normalized_curl' => $variant->normalized,
                'curl_hash' => $variant->hash,
                'signature_version' => $variant->name === 'V6' ? 6 : ($endpoint->exists && $endpoint->signature_version !== 6 ? $endpoint->signature_version : 2),
                ...$matching,
                'exclude_cookies' => $this->excludeHeaders ? false : $this->excludeCookies,
                'exclude_auth' => $this->excludeHeaders ? false : $this->excludeAuth,
                'exclude_headers' => $this->excludeHeaders,
            ])->save();
            $endpoint->tags()->sync(array_map('intval', $this->tagIds));
            $overrides = collect($this->environmentOverrides)
                ->filter(static fn (string $enabled): bool => $enabled !== '')
                ->mapWithKeys(static fn (string $enabled, int|string $environmentId): array => [
                    (int) $environmentId => ['enabled' => $enabled === '1'],
                ])->all();
            $endpoint->environmentOverrides()->sync($overrides);
            if ($before !== null) {
                $revisions->recordIfChanged($endpoint, $before);
            }
        }, 3);

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
                $parsed = $parser->parse($this->rawCurl);
                $pathSegments = explode('/', (string) (parse_url($parsed->url, PHP_URL_PATH) ?: '/'));
                $matching = $this->fieldMatching($parsed->url);
                $variant = $hasher->forOptions(
                    $parsed,
                    $this->excludeHeaders ? false : $this->excludeCookies,
                    $this->excludeHeaders ? false : $this->excludeAuth,
                    $this->excludeHeaders,
                    $matching['excluded_query_params'],
                    $matching['excluded_headers'],
                    $matching['path_pattern_enabled'],
                );
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
        $parts = parse_url($parsed->url) ?: [];
        $target = (string) ($parts['path'] ?? '/');

        return strtoupper($parsed->method).' '.$target;
    }

    /** @return list<array{name: string, value: string, display_value: string, sensitive: bool, excluded_reason: ?string}> */
    private function analyzeHeaders(ParsedCurl $parsed): array
    {
        $transport = array_map('strtolower', config('mock.transport_header_names', []));
        $auth = array_map('strtolower', config('mock.auth_header_names', []));

        return array_map(function (array $header) use ($transport, $auth): array {
            $name = strtolower(trim($header['name']));
            $isSensitive = app(RequestCredentialPolicy::class)->sensitive($name, $header['value']);
            $reason = null;

            if (in_array($name, $transport, true)) {
                $reason = 'ignored: volatile transport header';
            } elseif ($this->excludeHeaders) {
                $reason = 'ignored: all headers excluded';
            } elseif ($this->excludeCookies && $name === 'cookie') {
                $reason = 'ignored: cookie policy';
            } elseif ($this->excludeAuth && in_array($name, $auth, true)) {
                $reason = 'ignored: authentication policy';
            }

            $coarseExcluded = $reason !== null;
            if ($reason === null && in_array($name, $this->excludedHeaderNames, true)) {
                $reason = 'ignored: individual exclusion';
            }

            return [
                'normalized_name' => $name,
                'coarse_excluded' => $coarseExcluded,
                'name' => $header['name'],
                'value' => $header['value'],
                'display_value' => $isSensitive ? '••••••••' : $header['value'],
                'sensitive' => $isSensitive,
                'excluded_reason' => $reason,
            ];
        }, $parsed->headers);
    }

    /** @return list<array{key: string, value: string, display_value: string, sensitive: bool}> */
    private function queryParameters(ParsedCurl $parsed): array
    {
        $query = (string) (parse_url($parsed->url, PHP_URL_QUERY) ?? '');
        if ($query === '') {
            return [];
        }

        return array_map(function (string $parameter): array {
            [$key, $value] = array_pad(explode('=', $parameter, 2), 2, '');
            $decodedKey = urldecode($key);
            $decodedValue = urldecode($value);
            $sensitive = app(RequestCredentialPolicy::class)->sensitive($decodedKey, $decodedValue, true);

            return [
                'key' => $decodedKey,
                'excluded' => in_array($decodedKey, $this->excludedQueryParams, true),
                'value' => $decodedValue,
                'display_value' => $sensitive ? '••••••••' : $decodedValue,
                'sensitive' => $sensitive,
            ];
        }, explode('&', $query));
    }

    /** @param list<array{name: string, value: string, display_value: string, sensitive: bool, excluded_reason: ?string}> $headers */
    private function maskCanonical(string $canonical, array $headers): string
    {
        foreach ($headers as $header) {
            if ($header['sensitive']) {
                $canonical = (string) preg_replace(
                    '/^'.preg_quote(strtolower($header['name']), '/').':.*$/mi',
                    strtolower($header['name']).':••••••••',
                    $canonical,
                );
            }
        }

        foreach (config('mock.portable_config.sensitive_query_keys', []) as $key) {
            $canonical = (string) preg_replace(
                '/([?&]'.preg_quote(rawurlencode((string) $key), '/').'=)[^&\s]+/i',
                '$1REDACTED',
                $canonical,
            );
        }

        return $canonical;
    }

    private function prettyBody(string $body): string
    {
        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE
            ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $body;
    }

    /** @return list<string> */
    private function curlWarnings(): array
    {
        $warnings = [];
        $checks = [
            '/(?:^|\s)(?:-L|--location)(?:\s|$)/' => 'Redirect flags are ignored; only the pasted request URL is signed.',
            '/(?:^|\s)(?:-G|--get)(?:\s|$)/' => 'GET mode moves data fields into the query string before signing.',
            '/(?:^|\s)(?:-u|--user)(?:\s|$)/' => 'Basic-auth credentials are converted into an Authorization header.',
            '/(?:^|\s)(?:-b|--cookie)(?:\s|$)/' => 'Inline cookies are converted into a Cookie header.',
            '/(?:^|\s)(?:-F|--form)(?:\s|$)/' => 'Multipart forms are unsupported because generated boundaries are unstable.',
            '/--data-binary\s+@/' => 'File-backed request bodies are unsupported; paste the file contents inline.',
        ];

        foreach ($checks as $pattern => $message) {
            if (preg_match($pattern, $this->rawCurl) === 1) {
                $warnings[] = $message;
            }
        }

        if (preg_match_all('/(?:^|\R)\s*curl(?:\.exe)?\b/i', $this->rawCurl) > 1) {
            $warnings[] = 'Multiple curl commands were detected. Paste one request at a time.';
        }

        return $warnings;
    }
}
