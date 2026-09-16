<?php

namespace App\Models;

use Database\Factories\MockResponseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MockResponse extends Model
{
    /** @use HasFactory<MockResponseFactory> */
    use HasFactory;

    protected $fillable = [
        'status_code',
        'headers',
        'body',
        'delay_ms',
        'weight',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'status_code' => 'integer',
            'delay_ms' => 'integer',
            'weight' => 'integer',
        ];
    }

    /** @return BelongsTo<MockEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(MockEndpoint::class, 'mock_endpoint_id');
    }
}
