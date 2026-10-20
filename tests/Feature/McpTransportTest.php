<?php

namespace Tests\Feature;

use App\Http\Middleware\DashboardAccess;
use App\Services\Mcp\McpCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class McpTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_transport_is_separate_from_dashboard_sessions_and_revocation_is_immediate(): void
    {
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']]];
        config(['mock.dashboard_auth.enabled' => true]);
        $this->withSession([DashboardAccess::SESSION_KEY => true])->get('/dashboard')->assertOk();
        $this->postJson('/mcp', $body)->assertUnauthorized();
        $issued = app(McpCredentialService::class)->generate('client', ['read']);
        $token = $issued->reveal();
        $this->postJson('/mcp', $body, ['Authorization' => 'Bearer '.$token])->assertOk()->assertJsonPath('result.serverInfo.name', 'MockDeck');
        app(McpCredentialService::class)->revoke($issued->credential->id);
        $this->postJson('/mcp', $body, ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }
}
