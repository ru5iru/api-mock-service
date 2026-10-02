<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ResponseRule extends Model
{
    public $timestamps = false;

    protected $fillable = ['field_type', 'field_name', 'operator', 'value', 'priority'];

    protected function casts(): array
    {
        return ['priority' => 'integer'];
    }

    public function response(): BelongsTo
    {
        return $this->belongsTo(MockResponse::class, 'response_id');
    }
}
