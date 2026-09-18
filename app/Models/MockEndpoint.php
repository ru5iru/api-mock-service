<?php

namespace App\Models;

use Database\Factories\MockEndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MockEndpoint extends Model
{
    /** @use HasFactory<MockEndpointFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'enabled',
        'priority',
        'method',
        'raw_curl',
        'normalized_curl',
        'curl_hash',
        'signature_version',
        'exclude_cookies',
        'exclude_auth',
        'exclude_headers',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'priority' => 'integer',
            'signature_version' => 'integer',
            'exclude_cookies' => 'boolean',
            'exclude_auth' => 'boolean',
            'exclude_headers' => 'boolean',
        ];
    }

    /** @return HasMany<MockResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(MockResponse::class)->orderBy('id');
    }
}
