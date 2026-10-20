<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class McpUnmatchedRequest extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['request'];

    protected function casts(): array
    {
        return ['request' => 'encrypted:array', 'complete' => 'boolean', 'created_at' => 'immutable_datetime'];
    }
}
