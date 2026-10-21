<?php

namespace App\Services\Mcp;

use App\Models\McpCredential;
use App\Models\McpInvocation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class McpDispatcher
{
    public function call(string $tool, array $input, McpCredential $owner): array
    {
        $correlation = (string) Str::uuid();
        $run = null;
        $preview = $tool === 'preview_template';
        try {
            $operation = function () use ($tool, $input, $owner, $correlation, $preview, &$run): array {
                // SQLite ignores FOR UPDATE. Reserve its single writer before any
                // reads to avoid a deferred read-to-write upgrade deadlock. The
                // no-op does not change credential data; PostgreSQL uses row locks.
                if (! $preview && DB::getDriverName() === 'sqlite') {
                    DB::table('mcp_credentials')->where('id', $owner->id)->update(['last_used_at' => DB::raw('last_used_at')]);
                }
                app(McpCredentialService::class)->authorize($owner, McpToolPermissions::required($tool));
                app(McpToolRegistry::class)->validate($tool, $input);
                if (isset($input['run_id'])) {
                    $run = app(McpReads::class)->run($owner, $input['run_id'], ! $preview);
                }
                $data = in_array($tool, ['start_test_run', 'create_mock', 'update_mock', 'reset_test_run', 'finish_test_run', 'assert_calls'], true)
                    ? app(McpWrites::class)->execute($tool, $input, $owner, $run)
                    : app(McpReads::class)->execute($tool, $input, $owner, $run);
                if (! $preview) {
                    $this->audit($owner, $tool, $input, $correlation, null);
                }

                return ['ok' => true, 'data' => app(McpOutput::class)->safe($data, $run), 'correlation_id' => $correlation];
            };

            // Preview's explicit no-persistence contract also excludes operational
            // audit/last-used writes; all other tools retain their transaction/audit.
            return $preview ? $operation() : DB::transaction($operation, 5);
        } catch (Throwable $exception) {
            [$code, $message, $details] = match (true) {
                $exception instanceof McpDomainException => [$exception->errorCode, $exception->getMessage(), $exception->details],
                $exception instanceof ValidationException => [McpErrorCode::ValidationError, 'The proposed configuration is invalid.', ['errors' => $exception->errors()]],
                $exception instanceof AuthenticationException => [McpErrorCode::AuthError, 'A valid MCP bearer credential is required.', []],
                $exception instanceof AuthorizationException => [McpErrorCode::Forbidden, $exception->getMessage(), []],
                default => [McpErrorCode::ValidationError, 'The operation could not be completed. Check the bounded input and correlation ID.', []],
            };
            if (! ($exception instanceof McpDomainException || $exception instanceof ValidationException || $exception instanceof AuthenticationException || $exception instanceof AuthorizationException)) {
                // Never log exception messages here: parser/database errors can include a secret input.
                logger()->error('MCP operation failed', ['correlation_id' => $correlation, 'exception_type' => $exception::class]);
            }
            if (! $preview) {
                $this->audit($owner, $tool, $input, $correlation, $code);
            }

            return ['ok' => false, 'error' => app(McpOutput::class)->safe(['code' => $code->value, 'message' => $message, ...($details === [] ? [] : ['details' => $details])], $run), 'correlation_id' => $correlation];
        }
    }

    private function audit(McpCredential $owner, string $tool, array $input, string $correlation, ?McpErrorCode $code): void
    {
        request()->attributes->set('mcp_tool_audited', true);
        McpInvocation::query()->create(['credential_id' => $owner->id, 'tool_name' => substr($tool, 0, 128),
            'target' => isset($input['mock_id']) && is_string($input['mock_id']) ? substr($input['mock_id'], 0, 255) : null,
            'run_id' => isset($input['run_id']) && is_string($input['run_id']) && Str::isUuid($input['run_id']) ? $input['run_id'] : null,
            'result' => $code === null ? 'success' : 'error', 'error_code' => $code?->value, 'correlation_id' => $correlation, 'created_at' => now()]);
    }
}
