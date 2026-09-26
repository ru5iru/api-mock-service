<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Revision;
use App\Services\Revisions\RevisionManager;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

final class ImportRevisionController extends Controller
{
    public function show(string $batchId, RevisionManager $revisions): JsonResponse
    {
        $items = Revision::query()
            ->where('import_batch_id', $batchId)
            ->where('source', 'import')
            ->orderBy('entity_type')
            ->orderBy('entity_id')
            ->get()
            ->map(function (Revision $revision) use ($revisions): array {
                $snapshot = $revision->snapshot;

                return [
                    'revision_id' => $revision->id,
                    'entity_type' => $revision->entity_type,
                    'entity_id' => $revision->entity_id,
                    'name' => $revision->entity_type === 'endpoint'
                        ? ($snapshot['name'] ?: strtoupper((string) $snapshot['method']).' endpoint')
                        : 'Response '.($snapshot['status_code'] ?? $revision->entity_id),
                    'summary' => $revisions->summary($revision),
                ];
            })->values();

        abort_if($items->isEmpty(), 404);

        return response()->json(['data' => ['batch_id' => $batchId, 'items' => $items]]);
    }

    public function undo(string $batchId, RevisionManager $revisions): JsonResponse
    {
        try {
            return response()->json(['data' => $revisions->undoImport($batchId)]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
