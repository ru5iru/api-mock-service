<?php

namespace App\Livewire\Admin;

use App\Models\MockEndpoint;
use App\Services\Curl\CurlHasher;
use App\Services\Curl\CurlParser;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Component;

final class EndpointForm extends Component
{
    public ?int $endpointId = null;

    public string $name = '';

    public bool $enabled = true;

    public int $priority = 0;

    public string $rawCurl = '';

    public bool $excludeCookies = false;

    public bool $excludeAuth = true;

    public bool $excludeHeaders = false;

    public function mount(?MockEndpoint $endpoint = null): void
    {
        if ($endpoint === null) {
            $this->rawCurl = <<<'CURL'
curl --request POST 'https://api.example.test/v1/items?limit=10' \
  --header 'Content-Type: application/json' \
  --header 'Authorization: Bearer replace-me' \
  --data '{"name":"Example"}'
CURL;

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
        if ($value) {
            $this->excludeCookies = false;
            $this->excludeAuth = false;
        }
    }

    public function save(CurlParser $parser, CurlHasher $hasher): mixed
    {
        $this->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'enabled' => ['boolean'],
            'priority' => ['required', 'integer', 'between:-1000,1000'],
            'rawCurl' => ['required', 'string', 'max:1048576'],
            'excludeCookies' => ['boolean'],
            'excludeAuth' => ['boolean'],
            'excludeHeaders' => ['boolean'],
        ]);

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
            $label = $duplicate->name ?: 'Endpoint #'.$duplicate->id;
            $this->addError('rawCurl', "This request signature is already used by {$label}.");

            return null;
        }

        $endpoint = $this->endpointId === null
            ? new MockEndpoint
            : MockEndpoint::query()->findOrFail($this->endpointId);

        $endpoint->fill([
            'name' => trim($this->name) ?: null,
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

        session()->flash('status', $this->endpointId === null ? 'Mock endpoint created.' : 'Mock endpoint updated.');

        return $this->redirectRoute('dashboard.endpoints.edit', ['endpoint' => $endpoint->id], navigate: true);
    }

    public function render(): View
    {
        $parser = app(CurlParser::class);
        $hasher = app(CurlHasher::class);
        $preview = null;
        $previewError = null;

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
            } catch (InvalidArgumentException $exception) {
                $previewError = $exception->getMessage();
            }
        }

        return view('livewire.admin.endpoint-form', compact('preview', 'previewError'));
    }
}
