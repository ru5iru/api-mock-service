<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EnvironmentVariable extends Model
{
    protected $fillable = ['key', 'value', 'is_secret'];

    protected $hidden = ['value'];

    protected function casts(): array
    {
        return [
            'value' => 'encrypted',
            'is_secret' => 'boolean',
        ];
    }

    /** @return BelongsTo<Environment, $this> */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
