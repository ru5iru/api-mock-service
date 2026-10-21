<?php

namespace App\Services\Mutations;

use App\Services\Callbacks\CallbackDelivery;
use App\Services\Templates\TemplateCompiler;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

final class CallbackConfigurationValidator
{
    public function rules(): array
    {
        return [
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
        ];
    }

    public function attributes(array $validated, bool $secretSet = false): array
    {
        try {
            $callbackHeaders = trim((string) $validated['callbackHeadersJson']) === ''
                ? [] : json_decode($validated['callbackHeadersJson'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['callbackHeadersJson' => 'Enter a valid JSON object of callback headers.']);
        }
        if (! is_array($callbackHeaders) || ($callbackHeaders !== [] && array_is_list($callbackHeaders))) {
            throw ValidationException::withMessages(['callbackHeadersJson' => 'Callback headers must be a JSON object.']);
        }
        foreach ($callbackHeaders as $name => $value) {
            if (! is_string($name) || preg_match('/^[A-Za-z0-9!#$%&*+.^_`|~-]+$/D', $name) !== 1
                || ! is_string($value) || preg_match('/[\r\n]/', $value) === 1) {
                throw ValidationException::withMessages(['callbackHeadersJson' => 'Callback headers require single-line string names and values.']);
            }
        }

        if ($validated['callbackEnabled']) {
            if (! is_string($validated['callbackUrl']) || $validated['callbackUrl'] === '') {
                throw ValidationException::withMessages(['callbackUrl' => 'Enter a callback URL before enabling callbacks.']);
            }
            $sampleUrl = preg_replace('/\{\{[^{}]+\}\}|\$request\.[A-Za-z0-9_.-]+/', 'example.test', $validated['callbackUrl']);
            try {
                app(CallbackDelivery::class)->validateUrl($sampleUrl);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['callbackUrl' => 'Enter a well-formed HTTP(S) callback URL.']);
            }
            $validation = app(TemplateCompiler::class)->compile((string) ($validated['callbackBody'] ?: '{}'), $validated['locale'], true);
            if ($validation->hasErrors()) {
                throw ValidationException::withMessages(['callbackBody' => $validation->issues[0]->message]);
            }
        }
        if ($validated['callbackDelayMaxMs'] !== null && $validated['callbackDelayMaxMs'] < $validated['callbackDelayMs']) {
            throw ValidationException::withMessages(['callbackDelayMaxMs' => 'Maximum delay cannot be less than minimum delay.']);
        }
        $newSigningSecret = (string) ($validated['callbackSigningSecret'] ?? '');
        if ($validated['callbackSigningEnabled'] && $newSigningSecret === '' && ! ($validated['callbackSecretSet'] ?? $secretSet)) {
            throw ValidationException::withMessages(['callbackSigningSecret' => 'Enter a signing secret before enabling signatures.']);
        }

        $attributes = [
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

        return $attributes;
    }

    public function validate(array $values, bool $secretSet = false): array
    {
        $validated = Validator::make($values, $this->rules())->validate();

        return $this->attributes([...$validated, 'locale' => $values['locale']], $secretSet);
    }
}
