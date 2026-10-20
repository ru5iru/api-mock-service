<?php

namespace App\Services\TestRuns;

use App\Models\Environment;
use App\Models\McpCredential;
use App\Models\TestRun;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Internal lifecycle API only; Phase 2 intentionally exposes no management route. */
final readonly class TestRunService
{
    public function __construct(private McpCredentialService $credentials) {}

    public function create(McpCredential $owner, Environment $environment, string $label): TestRun
    {
        $this->credentials->authorize($owner, McpPermission::Write);
        Validator::make(['label' => $label, 'environment' => $environment->id], [
            'label' => ['required', 'string', 'max:255'], 'environment' => ['required', 'exists:environments,id'],
        ])->validate();

        return TestRun::query()->create(['credential_id' => $owner->id, 'environment_id' => $environment->id, 'label' => $label]);
    }

    public function finish(McpCredential $owner, string $publicId, bool $deleteMocks = false): TestRun
    {
        $this->credentials->authorize($owner, McpPermission::Cleanup);

        return DB::transaction(function () use ($owner, $publicId, $deleteMocks): TestRun {
            $run = TestRun::query()->where('credential_id', $owner->id)->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            if ($run->status !== 'finished') {
                $run->status = 'finished';
                $run->finished_at = now();
                $run->save();
            }
            if ($deleteMocks) {
                $run->endpoints()->delete();
            }

            return $run;
        });
    }
}
