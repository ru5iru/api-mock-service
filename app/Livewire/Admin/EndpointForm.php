<?php

namespace App\Livewire\Admin;

use App\Models\MockEndpoint;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use App\Services\Curl\ParsedCurl;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use InvalidArgumentException;
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

    public bool $excludeCookies = false;

    public bool $excludeAuth = true;

    public bool $excludeHeaders = false;

    /** @var list<string> */
    public array $touchedSections = [];

    public bool $submitAttempted = false;

    public function mount(?MockEndpoint $endpoint = null): void
    {
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
    }

    public function touchSection(string $section): void
    {
        if (in_array($section, ['request', 'matching', 'response'], true)
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
        $this->resetValidation('rawCurl');
    }

    public function maskSecrets(): void
    {
        $this->touchSection('request');
        $sensitiveHeaders = implode('|', array_map(
            static fn (string $name): string => preg_quote($name, '/'),
            config('mock.portable_config.sensitive_headers', []),
        ));

        if ($sensitiveHeaders !== '') {
            $this->rawCurl = (string) preg_replace_callback(
                '/((?:-H|--header)\s+)([\'\"])(('.$sensitiveHeaders.')\s*:\s*)(.*?)(\2)/i',
                static fn (array $match): string => $match[1].$match[2].$match[3].'REPLACE_ME'.$match[6],
                $this->rawCurl,
            );
        }

        foreach (config('mock.portable_config.sensitive_query_keys', []) as $key) {
            $this->rawCurl = (string) preg_replace(
                '/([?&]'.preg_quote((string) $key, '/').'=)[^&\'\"\s]+/i',
                '$1REPLACE_ME',
                $this->rawCurl,
            );
        }
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
        ]);

        if (trim($this->rawCurl) === trim(self::EXAMPLE_CURL)) {
            $this->addError('rawCurl', 'This is the sample curl. Replace its URL and values with the request you want to mock.');

            return null;
        }

        try {
            $parsed = $parser->parse($this->rawCurl);
            $variant = $hasher->forOptions(
                $parsed,
                $this->excludeHeaders ? false : $this->excludeCookies,
                $this->excludeHeaders ? false : $this->excludeAuth,
                $this->excludeHeaders,
            );
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

        $endpoint->fill([
            'name' => $displayName,
            'enabled' => $this->enabled,
            'priority' => $this->priority,
            'method' => $parsed->method,
            'raw_curl' => $this->rawCurl,
            'normalized_curl' => $variant->normalized,
            'curl_hash' => $variant->hash,
            'signature_version' => 2,
            'exclude_cookies' => $this->excludeHeaders ? false : $this->excludeCookies,
            'exclude_auth' => $this->excludeHeaders ? false : $this->excludeAuth,
            'exclude_headers' => $this->excludeHeaders,
        ])->save();

        session()->flash(
            'status',
            $created
                ? 'Endpoint created – add a response so it can answer requests.'
                : 'Endpoint changes saved.',
        );

        $destination = route('dashboard.endpoints.edit', ['endpoint' => $endpoint->id]);

        return $this->redirect($created ? $destination.'#responses' : $destination, navigate: true);
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

        if (trim($this->rawCurl) !== '') {
            try {
                $parsed = $parser->parse($this->rawCurl);
                $variant = $hasher->forOptions(
                    $parsed,
                    $this->excludeHeaders ? false : $this->excludeCookies,
                    $this->excludeHeaders ? false : $this->excludeAuth,
                    $this->excludeHeaders,
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
            } catch (InvalidArgumentException $exception) {
                $previewError = $exception->getMessage();
            }
        }

        return view('livewire.admin.endpoint-form', [
            'preview' => $preview,
            'previewError' => $previewError,
            'duplicate' => $duplicate,
            'derivedName' => $preview ? $this->deriveName($preview['parsed']) : 'METHOD /path',
            'headerAnalysis' => $headerAnalysis,
            'queryParameters' => $queryParameters,
            'prettyBody' => $prettyBody,
            'displayCanonical' => $displayCanonical,
            'curlWarnings' => $this->curlWarnings(),
            'containsSecrets' => collect($headerAnalysis)->contains('sensitive', true) || $this->containsSensitiveQuery(),
            'isExample' => trim($this->rawCurl) === trim(self::EXAMPLE_CURL),
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
        $sensitive = array_map('strtolower', config('mock.portable_config.sensitive_headers', []));
        $transport = array_map('strtolower', config('mock.transport_header_names', []));
        $auth = array_map('strtolower', config('mock.auth_header_names', []));

        return array_map(function (array $header) use ($sensitive, $transport, $auth): array {
            $name = strtolower(trim($header['name']));
            $isSensitive = in_array($name, $sensitive, true)
                && ! Str::contains(Str::lower($header['value']), ['replace_me', 'replace-me', 'redacted']);
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

            return [
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

        $sensitiveKeys = array_map('strtolower', config('mock.portable_config.sensitive_query_keys', []));

        return array_map(static function (string $parameter) use ($sensitiveKeys): array {
            [$key, $value] = array_pad(explode('=', $parameter, 2), 2, '');
            $decodedKey = urldecode($key);
            $decodedValue = urldecode($value);
            $sensitive = in_array(strtolower($decodedKey), $sensitiveKeys, true)
                && ! Str::contains(Str::lower($decodedValue), ['replace_me', 'replace-me', 'redacted']);

            return [
                'key' => $decodedKey,
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

    private function containsSensitiveQuery(): bool
    {
        foreach (config('mock.portable_config.sensitive_query_keys', []) as $key) {
            if (preg_match('/[?&]'.preg_quote((string) $key, '/').'=([^&\s]+)/i', $this->rawCurl, $matches) === 1
                && ! Str::contains(Str::lower($matches[1]), ['replace_me', 'replace-me', 'redacted'])) {
                return true;
            }
        }

        return false;
    }
}
