<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Revision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'entity_type',
        'entity_id',
        'version_number',
        'snapshot',
        'source',
        'import_batch_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'version_number' => 'integer',
            'snapshot' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
