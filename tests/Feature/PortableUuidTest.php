<?php

namespace Tests\Feature;

use App\Models\MockEndpoint;
use App\Models\MockResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class PortableUuidTest extends TestCase
{
    use RefreshDatabase;

    public function test_models_receive_stable_portable_uuids(): void
    {
        $endpoint = MockEndpoint::factory()->create();
        $response = MockResponse::factory()->for($endpoint, 'endpoint')->create();

        self::assertTrue(Str::isUuid($endpoint->uuid));
        self::assertTrue(Str::isUuid($response->uuid));
        self::assertSame($endpoint->uuid, $endpoint->fresh()->uuid);
        self::assertSame($response->uuid, $response->fresh()->uuid);
    }

    public function test_endpoint_uuid_cannot_be_changed_after_creation(): void
    {
        $endpoint = MockEndpoint::factory()->create();

        $this->expectException(LogicException::class);

        $endpoint->update(['uuid' => (string) Str::uuid7()]);
    }
}
