<?php

namespace App\Services\Matching;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class FieldMatchingConfiguration
{
    public function __construct(private PathPattern $patterns) {}

    /** Validate portable, revision and editor fields using one schema. Null means the compatible default. */
    public function validate(array $attributes, ?string $url = null): array
    {
        $data = [
            'excluded_query_params' => $attributes['excluded_query_params'] ?? [],
            'excluded_headers' => $attributes['excluded_headers'] ?? [],
            'path_pattern_enabled' => $attributes['path_pattern_enabled'] ?? false,
        ];
        Validator::make($data, [
            'excluded_query_params' => ['array', 'max:100'],
            'excluded_query_params.*' => ['string', 'min:1', 'max:255'],
            'excluded_headers' => ['array', 'max:100'],
            'excluded_headers.*' => ['string', 'max:255', 'regex:/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/D'],
            'path_pattern_enabled' => ['boolean'],
        ])->validate();
        foreach (['excluded_query_params', 'excluded_headers'] as $key) {
            if (! array_is_list($data[$key])) {
                throw ValidationException::withMessages([$key => 'Exclusions must be a list of field names.']);
            }
        }
        $data['excluded_query_params'] = array_values(array_unique($data['excluded_query_params']));
        $data['excluded_headers'] = array_values(array_unique(array_map('strtolower', $data['excluded_headers'])));
        $data['path_pattern_enabled'] = (bool) $data['path_pattern_enabled'];
        if ($data['path_pattern_enabled'] && $url !== null) {
            try {
                $this->patterns->validate((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['path_pattern_enabled' => $exception->getMessage()]);
            }
        }

        return $data;
    }
}
