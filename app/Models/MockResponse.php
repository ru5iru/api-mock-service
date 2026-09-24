<?php

namespace App\Models;

use Database\Factories\MockResponseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use LogicException;

final class MockResponse extends Model
{
    /** @use HasFactory<MockResponseFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'status_code',
        'headers',
        'body',
        'body_mode',
        'template',
        'editor_view',
        'seed_mode',
        'seed',
        'locale',
        'delay_ms',
        'weight',
    ];

    protected static function booted(): void
    {
        self::creating(function (MockResponse $response): void {
            $response->uuid ??= (string) Str::uuid7();
        });

        self::updating(function (MockResponse $response): void {
            if ($response->isDirty('uuid')) {
                throw new LogicException('Response UUIDs are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'status_code' => 'integer',
            'delay_ms' => 'integer',
            'weight' => 'integer',
            'seed' => 'integer',
        ];
    }

    /** @return BelongsTo<MockEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(MockEndpoint::class, 'mock_endpoint_id');
    }
}
