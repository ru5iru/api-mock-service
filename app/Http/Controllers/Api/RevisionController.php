<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MockEndpoint;
use App\Models\MockResponse;
use App\Models\Revision;
use App\Services\Revisions\RevisionManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

final class RevisionController extends Controller
{
    public function endpointIndex(MockEndpoint $endpoint, RevisionManager $revisions): JsonResponse
    {
        return $this->index($endpoint, $revisions);
    }

    public function responseIndex(MockResponse $response, RevisionManager $revisions): JsonResponse
    {
        return $this->index($response, $revisions);
    }

    public function endpointDiff(MockEndpoint $endpoint, Revision $a, Revision $b, RevisionManager $revisions): JsonResponse
    {
        $this->assertRevision($a, 'endpoint', $endpoint->id);
        $this->assertRevision($b, 'endpoint', $endpoint->id);

        return response()->json(['data' => $revisions->diff($a, $b)]);
    }

    public function responseDiff(MockResponse $response, Revision $a, Revision $b, RevisionManager $revisions): JsonResponse
    {
        $this->assertRevision($a, 'response', $response->id);
        $this->assertRevision($b, 'response', $response->id);

        return response()->json(['data' => $revisions->diff($a, $b)]);
    }

    public function endpointDiffCurrent(MockEndpoint $endpoint, Revision $revision, RevisionManager $revisions): JsonResponse
    {
        $this->assertRevision($revision, 'endpoint', $endpoint->id);

        return response()->json(['data' => $revisions->diffCurrent($revision)]);
    }

    public function responseDiffCurrent(MockResponse $response, Revision $revision, RevisionManager $revisions): JsonResponse
    {
        $this->assertRevision($revision, 'response', $response->id);

        return response()->json(['data' => $revisions->diffCurrent($revision)]);
    }

    public function endpointRestore(MockEndpoint $endpoint, Revision $revision, RevisionManager $revisions): JsonResponse
    {
        $this->assertRevision($revision, 'endpoint', $endpoint->id);

        return response()->json(['data' => $this->revisionData($revisions->restore($revision), $revisions)]);
    }

    public function responseRestore(MockResponse $response, Revision $revision, RevisionManager $revisions): JsonResponse
    {
        $this->assertRevision($revision, 'response', $response->id);

        return response()->json(['data' => $this->revisionData($revisions->restore($revision), $revisions)]);
    }

    private function index(Model $entity, RevisionManager $revisions): JsonResponse
    {
        return response()->json([
            'data' => $entity->revisions()->latest('version_number')->get()->map(
                fn (Revision $revision): array => $this->revisionData($revision, $revisions),
            )->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function revisionData(Revision $revision, RevisionManager $revisions): array
    {
        return [
            'id' => $revision->id,
            'version_number' => $revision->version_number,
            'source' => $revision->source,
            'import_batch_id' => $revision->import_batch_id,
            'note' => $revision->note,
            'summary' => $revisions->summary($revision),
            'created_at' => $revision->created_at?->toIso8601String(),
        ];
    }

    private function assertRevision(Revision $revision, string $type, int $entityId): void
    {
        abort_unless($revision->entity_type === $type && $revision->entity_id === $entityId, 404);
    }
}
