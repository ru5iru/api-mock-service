<?php

namespace App\Models;

use Database\Factories\MockEndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

final class MockEndpoint extends Model
{
    /** @use HasFactory<MockEndpointFactory> */
    use HasFactory;

    protected $fillable = [
        'external_source',
        'uuid',
        'selection_mode',
        'sequence_on_exhaust',
        'collection_id',
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
        'excluded_query_params',
        'excluded_headers',
        'path_pattern_enabled',
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
            'external_source' => 'array',
            'enabled' => 'boolean',
            'priority' => 'integer',
            'signature_version' => 'integer',
            'exclude_cookies' => 'boolean',
            'exclude_auth' => 'boolean',
            'exclude_headers' => 'boolean',
            'excluded_query_params' => 'array',
            'excluded_headers' => 'array',
            'path_pattern_enabled' => 'boolean',
        ];
    }

    /** @return HasMany<MockResponse, $this> */
    public function responses(): HasMany
    {
        return $this->hasMany(MockResponse::class)->orderBy('id');
    }

    /** @return BelongsTo<Collection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'endpoint_tags', 'endpoint_id', 'tag_id')->orderBy('name');
    }

    /** @return BelongsToMany<Environment, $this> */
    public function environmentOverrides(): BelongsToMany
    {
        return $this->belongsToMany(
            Environment::class,
            'endpoint_environment_overrides',
            'endpoint_id',
            'environment_id',
        )
            ->withPivot('enabled')
            ->withTimestamps()
            ->orderBy('name');
    }

    /** @return HasMany<Revision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class, 'entity_id')
            ->where('entity_type', 'endpoint');
    }

    public function callStates(): HasMany
    {
        return $this->hasMany(EndpointCallState::class, 'endpoint_id');
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

    public function hasFieldMatching(): bool
    {
        return (bool) $this->path_pattern_enabled || ($this->excluded_query_params ?? []) !== [] || ($this->excluded_headers ?? []) !== [];
    }

    public function signatureVariant(): string
    {
        if ($this->hasFieldMatching()) {
            return 'V6';
        }

        return $this->exclude_headers
            ? 'V5'
            : ($this->exclude_cookies && $this->exclude_auth
                ? 'V4'
                : ($this->exclude_auth ? 'V3' : ($this->exclude_cookies ? 'V2' : 'V1')));
    }
}
