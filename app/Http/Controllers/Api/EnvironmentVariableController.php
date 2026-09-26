<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class EnvironmentVariableController extends Controller
{
    public function index(Environment $environment): JsonResponse
    {
        return response()->json($environment->variables->map(fn (EnvironmentVariable $variable): array => $this->resource($variable))->values());
    }

    public function store(Request $request, Environment $environment): JsonResponse
    {
        $variable = $environment->variables()->create($this->validated($request, $environment));

        return response()->json($this->resource($variable), 201);
    }

    public function update(Request $request, Environment $environment, EnvironmentVariable $variable): JsonResponse
    {
        abort_unless($variable->environment_id === $environment->id, 404);
        $data = $this->validated($request, $environment, $variable);
        if ($variable->is_secret && ! $data['is_secret']
            && (! array_key_exists('value', $data) || $data['value'] === '••••••••')) {
            throw ValidationException::withMessages([
                'value' => 'Enter a replacement value before making a secret variable public.',
            ]);
        }
        if (($data['value'] ?? null) === '••••••••' && $variable->is_secret) {
            unset($data['value']);
        }
        $variable->update($data);

        return response()->json($this->resource($variable->refresh()));
    }

    public function show(Environment $environment, EnvironmentVariable $variable): JsonResponse
    {
        abort_unless($variable->environment_id === $environment->id, 404);

        return response()->json($this->resource($variable));
    }

    public function destroy(Environment $environment, EnvironmentVariable $variable): JsonResponse
    {
        abort_unless($variable->environment_id === $environment->id, 404);
        $variable->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Environment $environment, ?EnvironmentVariable $variable = null): array
    {
        return $request->validate([
            'key' => [
                'required', 'string', 'max:120', 'regex:/^[A-Za-z_][A-Za-z0-9_.-]*$/',
                Rule::unique('environment_variables')->where('environment_id', $environment->id)->ignore($variable?->id),
            ],
            'value' => [$variable === null ? 'required' : 'sometimes', 'string', 'max:65535'],
            'is_secret' => ['required', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function resource(EnvironmentVariable $variable): array
    {
        return [
            'id' => $variable->id,
            'environment_id' => $variable->environment_id,
            'key' => $variable->key,
            'value' => $variable->is_secret ? '••••••••' : $variable->value,
            'is_secret' => $variable->is_secret,
            'has_value' => $variable->value !== null && $variable->value !== '',
        ];
    }
}
