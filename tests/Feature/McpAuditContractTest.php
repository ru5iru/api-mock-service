<?php

namespace Tests\Feature;

use App\Models\McpInvocation;
use App\Services\Config\ConfigExporter;
use App\Services\Mcp\McpAuditRedactor;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class McpAuditContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_nested_audit_data_reuses_the_existing_secret_name_and_credential_policies(): void
    {
        config(['mock.portable_config.sensitive_headers' => ['authorization', 'cookie', 'set-cookie', 'x-custom-private']]);
        $plain = 'mdm_'.str_repeat('a', 64);
        $result = app(McpAuditRedactor::class)->redact([
            'Authorization' => 'Bearer private', 'custom_password' => 'password-value',
            'nested' => [['name' => 'Cookie', 'value' => 'session=private'], ['name' => 'x-custom-private', 'value' => 'custom-secret']],
            'json' => '{"password":"json-secret","safe":42}',
            'target' => 'https://user:password@host.test/path?stg_token=private&safe=yes',
            'free_text' => 'Bearer private '.$plain,
            'curl' => "curl 'https://host.test/?token=private' -H 'Cookie: session=private'",
        ]);
        $json = json_encode($result);
        foreach (['private', 'password-value', 'custom-secret', 'json-secret', $plain, 'user:password'] as $secret) {
            // The configured header's name can contain "private"; inspect values rather than that key.
            if ($secret !== 'private') {
                self::assertStringNotContainsString($secret, $json);
            }
        }
        self::assertSame('[REDACTED]', $result['Authorization']);
        self::assertSame('[REDACTED]', $result['nested'][0]['value']);
        self::assertSame('[REDACTED]', $result['nested'][1]['value']);
        self::assertStringNotContainsString('=private', $json);
        self::assertStringContainsString('safe=yes', $result['target']);
        self::assertStringContainsString('42', $result['json']);
    }

    public function test_schema_is_ready_but_authentication_does_not_write_invocation_rows(): void
    {
        $issued = app(McpCredentialService::class)->generate('audit actor', ['read']);
        app(McpCredentialService::class)->authenticate($issued->reveal());
        self::assertSame(0, McpInvocation::query()->count());
        self::assertSame(['id', 'credential_id', 'tool_name', 'target', 'run_id', 'result', 'error_code', 'correlation_id', 'created_at'], Schema::getColumnListing('mcp_invocations'));
        $row = McpInvocation::query()->create([
            'credential_id' => $issued->credential->id, 'target' => 'https://host.test/?password=secret-value',
            'result' => 'error', 'error_code' => McpErrorCode::ValidationError, 'correlation_id' => (string) Str::uuid(),
        ]);
        self::assertNull($row->tool_name);
        self::assertNull($row->run_id);
        self::assertSame(McpErrorCode::ValidationError, $row->fresh()->error_code);
        self::assertNotNull($row->fresh()->created_at);
        self::assertStringNotContainsString('secret-value', DB::table('mcp_invocations')->sole()->target);
        app(McpCredentialService::class)->revoke($issued->credential->id);
        self::assertSame($issued->credential->id, $row->credential->id);
        $export = app(ConfigExporter::class)->export()->toJson();
        self::assertStringNotContainsString('mcp_credentials', $export);
        self::assertStringNotContainsString('audit actor', $export);
    }

    public function test_error_taxonomy_is_fixed_and_metadata_has_bounded_output(): void
    {
        self::assertSame(['VALIDATION_ERROR', 'AUTH_ERROR', 'FORBIDDEN', 'CONFLICT', 'RUN_NOT_FOUND', 'RUN_FINISHED', 'ASSERTION_FAILED', 'INCONCLUSIVE_EVIDENCE', 'IDEMPOTENCY_CONFLICT', 'NOT_FOUND'], array_column(McpErrorCode::cases(), 'value'));
        self::assertSame(4107, strlen(app(McpAuditRedactor::class)->redact(str_repeat('x', 10000))));
        $nested = 'secret';
        for ($index = 0; $index < 20; $index++) {
            $nested = ['child' => $nested];
        }
        self::assertStringContainsString('[TRUNCATED]', json_encode(app(McpAuditRedactor::class)->redact($nested)));
    }
}
