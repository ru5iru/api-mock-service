<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Collection extends Model
{
    protected $fillable = ['name', 'description'];

    /** @return HasMany<MockEndpoint, $this> */
    public function endpoints(): HasMany
    {
        return $this->hasMany(MockEndpoint::class);
    }
}
