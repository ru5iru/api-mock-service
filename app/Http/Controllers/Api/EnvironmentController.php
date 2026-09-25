<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Services\Environments\EnvironmentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class EnvironmentController extends Controller
{
    public function index(EnvironmentContext $context): JsonResponse
    {
        return response()->json([
            'active_environment_id' => $context->active()->id,
            'data' => Environment::query()->with('variables')->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn (Environment $environment): array => $this->resource($environment)),
        ]);
    }

    public function store(Request $request, EnvironmentContext $context): JsonResponse
    {
        $data = $this->validated($request);
        $environment = Environment::query()->create(['name' => $data['name'], 'is_default' => false]);
        if (($data['is_default'] ?? false) === true) {
            $context->makeDefault($environment);
        }

        return response()->json($this->resource($environment->refresh()->load('variables')), 201);
    }

    public function show(Environment $environment): JsonResponse
    {
        return response()->json($this->resource($environment->load('variables')));
    }

    public function update(Request $request, Environment $environment, EnvironmentContext $context): JsonResponse
    {
        $data = $this->validated($request, $environment);
        $environment->update(['name' => $data['name']]);
        if (($data['is_default'] ?? false) === true) {
            $context->makeDefault($environment);
        }

        return response()->json($this->resource($environment->refresh()->load('variables')));
    }

    public function destroy(Request $request, Environment $environment, EnvironmentContext $context): JsonResponse
    {
        $validated = $request->validate(['replacement_environment_id' => ['nullable', 'integer', 'exists:environments,id']]);
        $replacement = isset($validated['replacement_environment_id'])
            ? Environment::query()->findOrFail($validated['replacement_environment_id'])
            : null;

        try {
            $context->delete($environment, $replacement);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(null, 204);
    }

    public function duplicate(Environment $environment): JsonResponse
    {
        $copy = DB::transaction(function () use ($environment): Environment {
            $base = $environment->name.' copy';
            $name = $base;
            $suffix = 2;
            while (Environment::query()->where('name', $name)->exists()) {
                $name = $base.' '.$suffix++;
            }

            $copy = Environment::query()->create(['name' => $name, 'is_default' => false]);
            foreach ($environment->variables as $variable) {
                $copy->variables()->create([
                    'key' => $variable->key,
                    'value' => $variable->value,
                    'is_secret' => $variable->is_secret,
                ]);
            }

            return $copy->load('variables');
        }, 3);

        return response()->json($this->resource($copy), 201);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Environment $environment = null): array
    {
        if (is_string($request->input('name'))) {
            $request->merge(['name' => trim($request->input('name'))]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('environments')->ignore($environment?->id)],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function resource(Environment $environment): array
    {
        return [
            'id' => $environment->id,
            'name' => $environment->name,
            'is_default' => $environment->is_default,
            'variables' => $environment->variables->map(static fn ($variable): array => [
                'id' => $variable->id,
                'key' => $variable->key,
                'value' => $variable->is_secret ? '••••••••' : $variable->value,
                'is_secret' => $variable->is_secret,
                'has_value' => $variable->value !== null && $variable->value !== '',
            ])->values(),
        ];
    }
}
