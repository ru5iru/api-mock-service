<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use LogicException;

final class TestRun extends Model
{
    public $timestamps = false;

    protected $fillable = ['credential_id', 'label', 'environment_id'];

    protected static function booted(): void
    {
        self::creating(function (TestRun $run): void {
            $run->public_id = (string) Str::uuid();
            $run->status = 'active';
            $run->created_at = now();
        });
        self::updating(function (TestRun $run): void {
            if ($run->isDirty(['public_id', 'credential_id', 'environment_id'])) {
                throw new LogicException('Run identity, ownership and environment binding are immutable.');
            }
        });
    }

    protected function casts(): array
    {
        return ['final_summary' => 'array', 'created_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(McpCredential::class, 'credential_id');
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function endpoints(): HasMany
    {
        return $this->hasMany(MockEndpoint::class)->withoutGlobalScope('ordinary');
    }
}
