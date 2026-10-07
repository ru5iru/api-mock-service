<?php

namespace App\Services\Environments;

use App\Models\Environment;
use App\Models\EnvironmentVariable;
use Illuminate\Validation\Rule;

/** One validation and encrypted model-write path for API, editor and imports. */
final class EnvironmentVariableWriter
{
    public static function validKey(string $key): bool
    {
        return strlen($key) <= 120 && preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/D', $key) === 1;
    }

    public static function sensitiveName(string $key): bool
    {
        return preg_match('/token|secret|password|key|credential|authorization/i', $key) === 1;
    }

    public function create(Environment $environment, array $attributes): EnvironmentVariable
    {
        $data = validator($attributes, [
            'key' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z_][A-Za-z0-9_.-]*$/', Rule::unique('environment_variables')->where('environment_id', $environment->id)],
            'value' => ['present', 'string', 'max:65535'],
            'is_secret' => ['required', 'boolean'],
        ])->validate();

        return $environment->variables()->create($data);
    }
}
