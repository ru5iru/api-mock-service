<?php

namespace App\Services\Response;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class FaultConfigurationValidator
{
    public const TYPES = ['delay', 'malformed_body', 'truncated_body', 'timeout'];

    public const MAX_DELAY_MS = 120000;

    /** Compatible defaults for older revisions, exports and saved responses. */
    public static function defaults(): array
    {
        return [
            'fault_enabled' => false,
            'fault_type' => 'delay',
            'fault_delay_ms_min' => 0,
            'fault_delay_ms_max' => null,
            'fault_probability' => 100,
        ];
    }

    public function rules(): array
    {
        return [
            'fault_enabled' => ['required', 'boolean'],
            'fault_type' => ['required', Rule::in(self::TYPES)],
            'fault_delay_ms_min' => ['required', 'integer', 'between:0,'.self::MAX_DELAY_MS],
            'fault_delay_ms_max' => ['nullable', 'integer', 'between:0,'.self::MAX_DELAY_MS, 'gte:fault_delay_ms_min'],
            'fault_probability' => ['required', 'integer', 'between:0,100'],
        ];
    }

    /** Project already-validated or database attributes into the portable schema. */
    public function attributes(array $response): array
    {
        $attributes = [];
        foreach (self::defaults() as $field => $default) {
            $attributes[$field] = $response[$field] ?? $default;
        }
        $attributes['fault_enabled'] = (bool) $attributes['fault_enabled'];
        $attributes['fault_type'] = (string) $attributes['fault_type'];
        $attributes['fault_delay_ms_min'] = (int) $attributes['fault_delay_ms_min'];
        $attributes['fault_delay_ms_max'] = $attributes['fault_delay_ms_max'] === null ? null : (int) $attributes['fault_delay_ms_max'];
        $attributes['fault_probability'] = (int) $attributes['fault_probability'];

        return $attributes;
    }

    /** Return only portable fault fields; null legacy fields use compatible defaults. */
    public function validate(array $response): array
    {
        $attributes = [];
        foreach (self::defaults() as $field => $default) {
            $attributes[$field] = $response[$field] ?? $default;
        }
        $validated = Validator::make($attributes, $this->rules(), [
            'fault_delay_ms_max.gte' => 'Fault delay max must be at least the minimum delay.',
            'fault_probability.between' => 'Fault probability must be between 0 and 100 percent.',
        ])->validate();

        if ($validated['fault_enabled'] && $validated['fault_type'] === 'malformed_body'
            && ! self::supportsMalformedBody(self::contentType($response))) {
            throw ValidationException::withMessages([
                'fault_type' => 'Malformed body requires a JSON or XML Content-Type header. Choose Truncated body for other formats.',
            ]);
        }

        return $validated;
    }

    public static function contentType(array $response): string
    {
        foreach (is_array($response['headers'] ?? null) ? $response['headers'] : [] as $name => $value) {
            if (strcasecmp((string) $name, 'Content-Type') === 0) {
                return strtolower(trim(explode(';', (string) $value, 2)[0]));
            }
        }

        return ($response['body_mode'] ?? 'static') === 'template' ? 'application/json' : 'text/html';
    }

    public static function supportsMalformedBody(string $contentType): bool
    {
        return in_array($contentType, ['application/json', 'application/xml', 'text/xml'], true)
            || str_ends_with($contentType, '+json') || str_ends_with($contentType, '+xml');
    }
}
