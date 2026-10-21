<?php

namespace App\Services\Mutations;

use App\Services\Response\FaultConfigurationValidator;
use App\Services\Templates\ResponseTemplateEngine;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;

final class ResponseConfigurationValidator
{
    /** @return array<string, mixed> Validated persistence attributes. */
    public function validate(ResponseInput $input): array
    {
        $issues = null;
        $templates = app(ResponseTemplateEngine::class);
        $validated = Validator::make($input->values(), [
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
            ...app(CallbackConfigurationValidator::class)->rules(),
        ])->validate();

        try {
            $headers = trim($validated['headersJson']) === ''
                ? null
                : json_decode($validated['headersJson'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ResponseValidationException(['headersJson' => 'Headers must be a valid JSON object: '.$exception->getMessage()], $issues);
        }

        if ($headers !== null && (! is_array($headers) || ! json_decode($validated['headersJson']) instanceof \stdClass)) {
            throw new ResponseValidationException(['headersJson' => 'Headers must be a JSON object of header names and values.'], $issues);
        }

        foreach ($headers ?? [] as $name => $value) {
            $validName = is_string($name)
                && preg_match('/^[A-Za-z0-9!#$%&*+.^_`|~-]+$/', $name) === 1;

            if (! $validName || ! is_scalar($value) || preg_match('/[\r\n]/', (string) $value)) {
                throw new ResponseValidationException(['headersJson' => 'Header names and values must be single-line scalar values.'], $issues);
            }
        }

        if ($validated['seedMode'] === 'fixed' && $validated['seed'] === null) {
            throw new ResponseValidationException(['seed' => 'Enter an integer seed when Fixed is selected.'], $issues);
        }

        if ($validated['bodyMode'] === 'template') {
            $validation = $templates->validate((string) $validated['template'], $validated['locale']);
            $issues = $validation->issueArrays();
            if ($validation->hasErrors()) {
                $first = collect($validation->issues)->first(static fn ($issue): bool => $issue->severity === 'error');
                throw new ResponseValidationException(['template' => $first?->message ?? 'The response template is invalid.'], $issues);
            }
        }

        try {
            $callbackAttributes = app(CallbackConfigurationValidator::class)->attributes($validated, $input->callbackSecretSet);
            $faultAttributes = $this->faultAttributes($input, $headers, $validated['bodyMode']);
        } catch (ValidationException $exception) {
            throw new ResponseValidationException($exception->errors(), $issues);
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
        $attributes = array_merge($attributes, $callbackAttributes, $faultAttributes);

        return $attributes;
    }

    private function faultAttributes(ResponseInput $input, ?array $headers, string $bodyMode): array
    {
        $fields = [
            'fault_enabled' => 'faultEnabled',
            'fault_type' => 'faultType',
            'fault_delay_ms_min' => 'faultDelayMsMin',
            'fault_delay_ms_max' => 'faultDelayMsMax',
            'fault_probability' => 'faultProbability',
        ];
        $attributes = ['headers' => $headers, 'body_mode' => $bodyMode];
        foreach ($fields as $attribute => $property) {
            $attributes[$attribute] = $input->{$property};
        }
        try {
            return app(FaultConfigurationValidator::class)->validate($attributes);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $errors[$fields[$field] ?? $field] = $messages;
            }
            throw ValidationException::withMessages($errors);
        }
    }
}
