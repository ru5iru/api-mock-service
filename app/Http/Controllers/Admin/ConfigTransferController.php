<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Config\ConfigExporter;
use App\Services\Config\ConfigImporter;
use App\Services\Config\ImportMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class ConfigTransferController extends Controller
{
    public function export(Request $request, ConfigExporter $exporter): Response
    {
        $validated = $request->validate([
            'endpoint_uuids' => ['nullable', 'array'],
            'endpoint_uuids.*' => ['required', 'uuid', 'distinct', 'exists:mock_endpoints,uuid'],
            'redact_secrets' => ['nullable', 'boolean'],
            'confirm_sensitive_export' => ['nullable', 'boolean'],
            'collection_id' => ['nullable', 'integer', 'exists:collections,id', 'prohibits:environment_id'],
            'environment_id' => ['nullable', 'integer', 'exists:environments,id', 'prohibits:collection_id'],
        ]);

        $redactSecrets = $request->boolean('redact_secrets', true);
        if (! $redactSecrets && ! $request->boolean('confirm_sensitive_export')) {
            throw ValidationException::withMessages([
                'confirm_sensitive_export' => 'Unredacted exports require explicit confirmation.',
            ]);
        }

        try {
            $json = $exporter->export(
                $validated['endpoint_uuids'] ?? null,
                $redactSecrets,
                $validated['collection_id'] ?? null,
                $validated['environment_id'] ?? null,
            )->toJson();
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['export' => $exception->getMessage()]);
        }

        return response($json, 200, [
            'Content-Type' => 'application/vnd.mockdeck.config+json; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="mockdeck-export-'.now()->utc()->format('Ymd').'.json"',
        ]);
    }

    public function preview(Request $request, ConfigImporter $importer): JsonResponse
    {
        $validated = $request->validate([
            'config' => ['required', 'file', 'max:'.(int) ceil(config('mock.portable_config.max_bytes', 2097152) / 1024)],
            'mode' => ['required', 'in:create-only,upsert,clone'],
            'replace_responses' => ['nullable', 'boolean'],
        ]);

        if (strtolower($validated['config']->getClientOriginalExtension()) !== 'json') {
            return response()->json(['message' => 'Choose a .json MockDeck configuration file.'], 422);
        }

        $json = file_get_contents($validated['config']->getRealPath());
        if ($json === false) {
            return response()->json(['message' => 'The uploaded configuration could not be read.'], 422);
        }

        $plan = $importer->preview(
            $json,
            ImportMode::from($validated['mode']),
            (bool) ($validated['replace_responses'] ?? false),
        );

        return response()->json($plan->toArray(), $plan->canApply() ? 200 : 422);
    }

    public function apply(Request $request, ConfigImporter $importer): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:48'],
            'digest' => ['required', 'string', 'size:64'],
            'acknowledge_warnings' => ['nullable', 'boolean'],
        ]);

        try {
            $summary = $importer->apply(
                $validated['token'],
                $validated['digest'],
                (bool) ($validated['acknowledge_warnings'] ?? false),
            );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['summary' => $summary->toArray()]);
    }
}
