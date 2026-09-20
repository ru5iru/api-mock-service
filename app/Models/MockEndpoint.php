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
        static::creating(function (MockEndpoint $endpoint): void {
            $endpoint->uuid ??= (string) Str::uuid7();
        });

        static::updating(function (MockEndpoint $endpoint): void {
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

    public function displayName(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? $name : strtoupper($this->method).' '.$this->requestPath();
    }

    public function requestPath(): string
    {
        return explode('?', $this->requestTarget(), 2)[0] ?: '/';
    }

    public function requestTarget(): string
    {
        $lines = preg_split('/\R/', $this->normalized_curl) ?: [];
        $target = trim((string) ($lines[1] ?? ''));

        return $target !== '' ? $target : '/';
    }

    /** @return array{headers: int, body_bytes: int} */
    public function requestStats(): array
    {
        [$head, $body] = array_pad(explode("\n\n", $this->normalized_curl, 2), 2, '');
        $lines = preg_split('/\R/', $head) ?: [];

        return [
            'headers' => max(0, count($lines) - 2),
            'body_bytes' => strlen($body),
        ];
    }

    public function signatureVariant(): string
    {
        return $this->exclude_headers
            ? 'V5'
            : ($this->exclude_cookies && $this->exclude_auth
                ? 'V4'
                : ($this->exclude_auth ? 'V3' : ($this->exclude_cookies ? 'V2' : 'V1')));
    }
}
