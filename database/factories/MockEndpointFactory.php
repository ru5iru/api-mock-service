<?php

namespace Database\Factories;

use App\Models\MockEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MockEndpoint> */
final class MockEndpointFactory extends Factory
{
    protected $model = MockEndpoint::class;

    public function definition(): array
    {
        $normalized = "GET\n/users\n\n";

        return [
            'name' => fake()->words(3, true),
            'enabled' => true,
            'priority' => 0,
            'method' => 'GET',
            'raw_curl' => "curl 'https://api.example.test/users'",
            'normalized_curl' => $normalized,
            'curl_hash' => hash('sha256', $normalized),
            'signature_version' => 2,
            'exclude_cookies' => false,
            'exclude_auth' => false,
            'exclude_headers' => true,
        ];
    }
}
