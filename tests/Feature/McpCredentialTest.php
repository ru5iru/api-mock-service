<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateMcpCredential;
use App\Http\Middleware\DashboardAccess;
use App\Models\ApiToken;
use App\Models\McpCredential;
use App\Models\MockEndpoint;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpPermission;
use App\Services\Mcp\McpToolPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class McpCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_plaintext_is_one_time_and_permissions_are_explicit(): void
    {
        $service = app(McpCredentialService::class);
        $issued = $service->generate('read agent', ['read']);
        $plain = $issued->reveal();
        self::assertMatchesRegularExpression('/^mdm_[a-f0-9]{64}$/D', $plain);
        self::assertSame(hash('sha256', $plain), McpCredential::query()->sole()->token_hash);
        self::assertStringNotContainsString($plain, json_encode(DB::table('mcp_credentials')->first()));
        self::assertStringNotContainsString($plain, json_encode($issued));
        self::assertArrayNotHasKey('token_hash', $issued->credential->toArray());
        $credential = $service->authenticate($plain);
        $service->authorize($credential, McpPermission::Read);
        self::assertNotNull($credential->fresh()->last_used_at);
        self::assertFalse($credential->permits(McpPermission::Write));
        try {
            $issued->reveal();
            self::fail('A generated secret must not be shown twice.');
        } catch (\LogicException) {
            self::assertTrue(true);
        }
        $this->expectException(AuthorizationException::class);
        $service->authorize($credential, McpPermission::Write);
    }

    public function test_revocation_invalidates_stale_models_and_bearers_immediately(): void
    {
        $service = app(McpCredentialService::class);
        $issued = $service->generate('full agent', ['read', 'write', 'verify', 'cleanup']);
        $plain = $issued->reveal();
        $loaded = $service->authenticate($plain);
        $service->revoke($loaded->id);
        $revokedAt = $loaded->fresh()->revoked_at;
        $service->revoke($loaded->id);
        self::assertEquals($revokedAt, $loaded->fresh()->revoked_at);
        foreach (McpPermission::cases() as $permission) {
            self::assertFalse($loaded->permits($permission));
            try {
                $service->authorize($loaded, $permission);
                self::fail('Revoked models must fail authorization.');
            } catch (AuthenticationException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(AuthenticationException::class);
        $service->authenticate($plain);
    }

    public function test_cookie_is_never_enough_and_middleware_distinguishes_auth_from_permission(): void
    {
        $middleware = app(AuthenticateMcpCredential::class);
        $request = Request::create('/future-mcp');
        $request->setLaravelSession(app('session')->driver());
        $request->session()->put(DashboardAccess::SESSION_KEY, true);
        $next = fn () => response()->json(['ok' => true]);
        self::assertSame(401, $middleware->handle($request, $next)->getStatusCode());
        $issued = app(McpCredentialService::class)->generate('verify only', ['verify']);
        $request->headers->set('Authorization', 'Bearer '.$issued->reveal());
        $forbidden = $middleware->handle($request, $next, 'write');
        self::assertSame(403, $forbidden->getStatusCode());
        self::assertStringContainsString('FORBIDDEN', $forbidden->getContent());
        self::assertSame(200, $middleware->handle($request, $next, 'verify')->getStatusCode());
        self::assertSame(403, $middleware->handle($request, $next, 'invalid-permission')->getStatusCode());
    }

    public function test_existing_verification_tokens_are_unchanged_and_auth_domains_do_not_mix(): void
    {
        self::assertSame(['id', 'name', 'token_hash', 'last_used_at', 'created_at'], Schema::getColumnListing('api_tokens'));
        $plain = 'mdv_'.str_repeat('a', 64);
        ApiToken::query()->create(['name' => 'verification', 'token_hash' => hash('sha256', $plain)]);
        $endpoint = MockEndpoint::factory()->create();
        $url = '/api/v1/verify/endpoints/'.$endpoint->uuid.'/calls?environment=Development';
        $this->withToken($plain)->getJson($url)->assertOk();
        $service = app(McpCredentialService::class);
        try {
            $service->authenticate($plain);
            self::fail('Verification bearer must never become an MCP credential.');
        } catch (AuthenticationException) {
            self::assertSame(0, McpCredential::query()->count());
        }
        $mcp = $service->generate('mcp verify', ['verify'])->reveal();
        $this->withToken($mcp)->getJson($url)->assertUnauthorized();
        $this->withToken($plain)->getJson($url)->assertOk();
    }

    public function test_permission_mapping_is_a_fixed_contract_and_unknown_permissions_fail_validation(): void
    {
        self::assertSame([
            'start_test_run' => 'write', 'create_mock' => 'write', 'update_mock' => 'write',
            'list_mocks' => 'read', 'get_mock' => 'read', 'validate_mock' => 'read', 'get_mock_url' => 'read', 'explain_match' => 'read',
            'get_unmatched_requests' => 'verify', 'get_calls' => 'verify', 'assert_calls' => 'verify',
            'reset_test_run' => 'cleanup', 'finish_test_run' => 'cleanup',
        ], McpToolPermissions::REQUIRED);
        foreach (McpToolPermissions::REQUIRED as $tool => $permission) {
            self::assertSame($permission, McpToolPermissions::required($tool)->value);
        }
        $this->expectException(ValidationException::class);
        app(McpCredentialService::class)->generate('bad permissions', ['administrator']);
    }

    public function test_named_throttles_use_ip_for_authentication_and_identity_for_tools(): void
    {
        $request = Request::create('/future-mcp', server: ['REMOTE_ADDR' => '192.0.2.1']);
        $auth = RateLimiter::limiter('mcp-auth')($request);
        self::assertSame(30, $auth->maxAttempts);
        $issued = app(McpCredentialService::class)->generate('agent', ['read']);
        $request->attributes->set('mcp_credential', $issued->credential);
        $tools = RateLimiter::limiter('mcp')($request);
        self::assertSame(60, $tools->maxAttempts);
        self::assertSame('mcp:'.$issued->credential->id, $tools->key);
        self::assertSame(0, DB::table('api_tokens')->count());
    }

    public function test_issued_credentials_cannot_be_cloned_or_serialized_and_unknown_tools_fail_closed(): void
    {
        $issued = app(McpCredentialService::class)->generate('one time', ['read']);
        try {
            $copy = clone $issued;
            self::fail('Issuance must not be cloned to reveal twice.');
        } catch (\Error) {
            self::assertTrue(true);
        }
        try {
            serialize($issued);
            self::fail('Plaintext issuance must not be serialized.');
        } catch (\LogicException) {
            self::assertTrue(true);
        }
        $this->expectException(\InvalidArgumentException::class);
        McpToolPermissions::required('unknown_tool');
    }
}
