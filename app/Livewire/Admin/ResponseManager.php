<?php

namespace App\Livewire\Admin;

use App\Models\MockEndpoint;
use Illuminate\Contracts\View\View;
use JsonException;
use Livewire\Component;

final class ResponseManager extends Component
{
    public int $endpointId;

    public ?int $editingId = null;

    public int $statusCode = 200;

    public string $headersJson = "{\n  \"Content-Type\": \"application/json\"\n}";

    public string $body = "{\n  \"ok\": true\n}";

    public int $delayMs = 0;

    public int $weight = 1;

    public function mount(MockEndpoint $endpoint): void
    {
        $this->endpointId = $endpoint->id;
    }

    public function edit(int $responseId): void
    {
        $response = $this->endpoint()->responses()->findOrFail($responseId);
        $this->editingId = $response->id;
        $this->statusCode = $response->status_code;
        $this->headersJson = json_encode($response->headers ?? new \stdClass, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->body = (string) ($response->body ?? '');
        $this->delayMs = $response->delay_ms;
        $this->weight = $response->weight;
        $this->resetValidation();
    }

    public function createNew(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'statusCode' => ['required', 'integer', 'between:100,599'],
            'headersJson' => ['nullable', 'string', 'max:65535'],
            'body' => ['nullable', 'string'],
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

        $attributes = [
            'status_code' => $validated['statusCode'],
            'headers' => $headers,
            'body' => $validated['body'],
            'delay_ms' => $validated['delayMs'],
            'weight' => $validated['weight'],
        ];

        if ($this->editingId === null) {
            $this->endpoint()->responses()->create($attributes);
        } else {
            $this->endpoint()->responses()->findOrFail($this->editingId)->update($attributes);
        }

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

    public function render(): View
    {
        return view('livewire.admin.response-manager', [
            'responses' => $this->endpoint()->responses()->get(),
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
        $this->delayMs = 0;
        $this->weight = 1;
        $this->resetValidation();
    }
}
