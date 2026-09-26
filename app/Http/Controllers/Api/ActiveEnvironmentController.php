<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Environment;
use App\Services\Environments\EnvironmentContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ActiveEnvironmentController extends Controller
{
    public function show(EnvironmentContext $context): JsonResponse
    {
        return response()->json($context->active());
    }

    public function update(Request $request, EnvironmentContext $context): JsonResponse
    {
        $validated = $request->validate(['environment_id' => ['required', 'integer', 'exists:environments,id']]);

        return response()->json($context->activate(Environment::query()->findOrFail($validated['environment_id'])));
    }
}
