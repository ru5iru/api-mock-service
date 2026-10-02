<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EndpointCallState extends Model
{
    protected $table = 'endpoint_call_state';

    public $timestamps = false;

    protected $fillable = ['endpoint_id', 'environment_id', 'sequence_position', 'total_match_count', 'last_matched_at'];

    protected function casts(): array
    {
        return ['sequence_position' => 'integer', 'total_match_count' => 'integer', 'last_matched_at' => 'datetime'];
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(MockEndpoint::class, 'endpoint_id');
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }
}
