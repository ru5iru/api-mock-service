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
            'body_mode' => 'static',
            'template' => null,
            'editor_view' => 'builder',
            'seed_mode' => 'random',
            'seed' => null,
            'locale' => 'en',
            'delay_ms' => 0,
            'weight' => 1,
        ];
    }
}
