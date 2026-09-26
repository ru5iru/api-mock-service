<?php

namespace App\Models;

use Database\Factories\MockResponseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'callback_enabled',
        'callback_url',
        'callback_method',
        'callback_headers',
        'callback_body',
        'callback_delay_ms',
        'callback_delay_max_ms',
        'callback_retry',
        'callback_backoff_ms',
        'callback_timeout_ms',
        'callback_signing_enabled',
        'callback_signing_secret',
        'callback_signature_header',
    ];

    protected $hidden = ['callback_signing_secret'];

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
            'callback_enabled' => 'boolean',
            'callback_headers' => 'array',
            'callback_delay_ms' => 'integer',
            'callback_delay_max_ms' => 'integer',
            'callback_retry' => 'integer',
            'callback_backoff_ms' => 'integer',
            'callback_timeout_ms' => 'integer',
            'callback_signing_enabled' => 'boolean',
            'callback_signing_secret' => 'encrypted',
        ];
    }

    /** @return BelongsTo<MockEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(MockEndpoint::class, 'mock_endpoint_id');
    }

    /** @return HasMany<Revision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class, 'entity_id')
            ->where('entity_type', 'response');
    }

    /** @return HasMany<CallbackAttempt, $this> */
    public function callbackAttempts(): HasMany
    {
        return $this->hasMany(CallbackAttempt::class, 'response_id');
    }
}
