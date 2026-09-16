<?php

namespace Database\Factories;

use App\Models\MockEndpoint;
use App\Models\MockResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MockResponse> */
final class MockResponseFactory extends Factory
{
    protected $model = MockResponse::class;

    public function definition(): array
    {
        return [
            'mock_endpoint_id' => MockEndpoint::factory(),
            'status_code' => 200,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['ok' => true]),
            'delay_ms' => 0,
            'weight' => 1,
        ];
    }
}
