<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ApiToken extends Model
{
    public $timestamps = false;

    protected $fillable = ['name', 'token_hash', 'last_used_at', 'created_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'created_at' => 'datetime'];
    }
}
