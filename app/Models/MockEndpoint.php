<?php

namespace App\Models;

use Database\Factories\MockEndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

final class MockEndpoint extends Model
{
    /** @use HasFactory<MockEndpointFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
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

    protected static function booted(): void
    {
        self::creating(function (MockEndpoint $endpoint): void {
            $endpoint->uuid ??= (string) Str::uuid7();
        });

        self::updating(function (MockEndpoint $endpoint): void {
            if ($endpoint->isDirty('uuid')) {
                throw new LogicException('Endpoint UUIDs are immutable.');
            }
        });
    }

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
