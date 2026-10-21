<?php

namespace App\Services\Mcp;

use App\Models\TestRun;
use Illuminate\Support\Facades\DB;

/** Caller holds the run row lock and transaction; cached results contain redacted output only. */
final class McpIdempotency
{
    public function execute(TestRun $run, string $tool, array $input, callable $operation): array
    {
        DB::table('mcp_idempotency_keys')->where('created_at', '<', now()->subDay())->delete();
        $scope = ['credential_id' => $run->credential_id, 'run_id' => $run->id, 'tool_name' => $tool, 'idempotency_key' => $input['idempotency_key']];
        $hash = hash('sha256', json_encode($this->ordered($input), JSON_THROW_ON_ERROR));
        $cached = DB::table('mcp_idempotency_keys')->where($scope)->first();
        if ($cached !== null) {
            if (! hash_equals($hash, $cached->request_hash)) {
                throw new McpDomainException(McpErrorCode::IdempotencyConflict, 'This idempotency key was already used with different input.');
            }

            return json_decode($cached->cached_result, true, flags: JSON_THROW_ON_ERROR);
        }
        $result = $operation();
        DB::table('mcp_idempotency_keys')->insert([...$scope, 'request_hash' => $hash, 'cached_result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);

        return $result;
    }

    private function ordered(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->ordered(...), $value);
    }
}
