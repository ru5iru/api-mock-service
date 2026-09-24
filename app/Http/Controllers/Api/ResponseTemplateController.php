<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Templates\ResponseTemplateEngine;
use App\Services\Templates\TemplateIssue;
use App\Services\Templates\TemplateRenderException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ResponseTemplateController extends Controller
{
    public function validateTemplate(Request $request, ResponseTemplateEngine $engine): JsonResponse
    {
        $validated = $request->validate([
            'template' => ['required', 'string'],
            'locale' => ['sometimes', 'string', 'in:'.implode(',', config('mock.templates.locales', ['en']))],
        ]);

        return response()->json([
            'issues' => $engine->validate($validated['template'], $validated['locale'] ?? 'en')->issueArrays(),
        ]);
    }

    public function preview(Request $request, ResponseTemplateEngine $engine): JsonResponse
    {
        $validated = $request->validate([
            'template' => ['required', 'string'],
            'locale' => ['sometimes', 'string', 'in:'.implode(',', config('mock.templates.locales', ['en']))],
            'seed_mode' => ['sometimes', 'string', 'in:random,fixed,request'],
            'seed' => ['nullable', 'integer'],
        ]);

        $seedMode = $validated['seed_mode'] ?? 'random';
        if ($seedMode === 'fixed' && ! isset($validated['seed'])) {
            return response()->json([
                'output' => null,
                'bytes' => 0,
                'render_ms' => 0,
                'issues' => [(new TemplateIssue('error', 'BAD_ARGS', '/seed', 1, 1, 'A seed is required when seed mode is fixed.'))->toArray()],
            ], 422);
        }

        try {
            $result = $engine->preview(
                $validated['template'],
                $validated['locale'] ?? 'en',
                $seedMode,
                isset($validated['seed']) ? (int) $validated['seed'] : null,
            );
        } catch (TemplateRenderException $exception) {
            return response()->json([
                'output' => null,
                'bytes' => 0,
                'render_ms' => 0,
                'issues' => [(new TemplateIssue(
                    'error',
                    $exception->issueCode,
                    $exception->templatePath,
                    1,
                    1,
                    $exception->getMessage(),
                ))->toArray()],
            ], 422);
        }

        $status = collect($result['issues'])->contains(static fn (array $issue): bool => $issue['severity'] === 'error') ? 422 : 200;

        return response()->json($result, $status);
    }
}
