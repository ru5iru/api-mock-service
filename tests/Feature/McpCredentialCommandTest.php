<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\McpCredential;
use App\Services\Mcp\McpCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class McpCredentialCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_cli_generates_lists_and_revokes_without_reprinting_secrets(): void
    {
        self::assertSame(0, Artisan::call('mockdeck:mcp-credential:generate', ['name' => 'CI agent', '--permission' => ['read', 'verify']]));
        self::assertSame(1, preg_match_all('/mdm_[a-f0-9]{64}/', Artisan::output(), $tokens));
        $raw = $tokens[0][0];
        $credential = McpCredential::query()->sole();
        self::assertSame(hash('sha256', $raw), $credential->token_hash);
        app(McpCredentialService::class)->authenticate($raw);
        self::assertSame(0, Artisan::call('mockdeck:mcp-credential:list'));
        $listing = Artisan::output();
        self::assertStringContainsString('CI agent', $listing);
        self::assertStringContainsString('read, verify', $listing);
        self::assertStringContainsString('active', $listing);
        self::assertStringNotContainsString('never', $listing);
        self::assertStringNotContainsString($raw, $listing);
        self::assertStringNotContainsString($credential->token_hash, $listing);
        self::assertSame(0, Artisan::call('mockdeck:mcp-credential:revoke', ['id' => $credential->id]));
        self::assertNotNull($credential->fresh()->revoked_at);
        self::assertStringNotContainsString($raw, Artisan::output());
        self::assertSame(0, Artisan::call('mockdeck:mcp-credential:revoke', ['id' => $credential->id]));
        self::assertSame(0, Artisan::call('mockdeck:mcp-credential:list'));
        self::assertStringNotContainsString('active', Artisan::output());
        self::assertSame(0, ApiToken::query()->count());
    }

    public function test_cli_requires_explicit_valid_permissions_and_handles_unknown_revoke(): void
    {
        self::assertSame(1, Artisan::call('mockdeck:mcp-credential:generate', ['name' => 'no grant']));
        self::assertSame(1, Artisan::call('mockdeck:mcp-credential:generate', ['name' => 'bad grant', '--permission' => ['admin']]));
        self::assertSame(0, McpCredential::query()->count());
        self::assertSame(1, Artisan::call('mockdeck:mcp-credential:revoke', ['id' => 'not-an-id']));
        self::assertSame(1, Artisan::call('mockdeck:mcp-credential:revoke', ['id' => '12345']));
        self::assertStringContainsString('MCP credential not found.', Artisan::output());
    }

    public function test_rotation_is_new_credential_then_explicit_revoke_not_implicit_replacement(): void
    {
        foreach (['first', 'replacement'] as $name) {
            self::assertSame(0, Artisan::call('mockdeck:mcp-credential:generate', ['name' => $name, '--permission' => ['read']]));
        }
        self::assertSame(2, McpCredential::query()->whereNull('revoked_at')->count());
        $first = McpCredential::query()->where('name', 'first')->sole();
        Artisan::call('mockdeck:mcp-credential:revoke', ['id' => $first->id]);
        self::assertSame(1, McpCredential::query()->whereNull('revoked_at')->count());
    }
}
