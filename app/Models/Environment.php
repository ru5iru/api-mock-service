<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Environment extends Model
{
    protected $fillable = ['name', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** @return HasMany<EnvironmentVariable, $this> */
    public function variables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->orderBy('key');
    }

    /** @return BelongsToMany<MockEndpoint, $this> */
    public function endpoints(): BelongsToMany
    {
        return $this->belongsToMany(
            MockEndpoint::class,
            'endpoint_environment_overrides',
            'environment_id',
            'endpoint_id',
        )
            ->withPivot('enabled')
            ->withTimestamps();
    }
}
