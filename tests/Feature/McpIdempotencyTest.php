<?php

namespace Tests\Feature;

use App\Models\Environment;
use App\Services\Mcp\McpCredentialService;
use App\Services\Mcp\McpDomainException;
use App\Services\Mcp\McpIdempotency;
use App\Services\TestRuns\TestRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class McpIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_is_cached_and_different_payload_conflicts(): void
    {
        $owner = app(McpCredentialService::class)->generate('test', ['write'])->credential;
        $run = app(TestRunService::class)->create($owner, Environment::query()->firstOrFail(), 'test');
        $calls = 0;
        $input = ['idempotency_key' => 'retry', 'body' => 'same'];
        $operation = function () use (&$calls): array {
            $calls++;

            return ['id' => 'original'];
        };
        DB::transaction(function () use ($run, $input, $operation): void {
            $service = app(McpIdempotency::class);
            self::assertSame(['id' => 'original'], $service->execute($run, 'create_mock', $input, $operation));
            self::assertSame(['id' => 'original'], $service->execute($run, 'create_mock', $input, $operation));
        });
        self::assertSame(1, $calls);
        $this->expectException(McpDomainException::class);
        app(McpIdempotency::class)->execute($run, 'create_mock', [...$input, 'body' => 'different'], $operation);
    }
}
