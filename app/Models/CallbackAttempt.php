<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CallbackAttempt extends Model
{
    protected $fillable = [
        'response_id', 'request_log_id', 'resend_of_id', 'retry_of_id', 'attempt_number', 'target_url',
        'method', 'resolved_headers', 'resolved_body', 'status', 'http_status', 'duration_ms', 'error',
    ];

    protected function casts(): array
    {
        return [
            'resolved_headers' => 'encrypted:array',
            'resolved_body' => 'encrypted',
            'target_url' => 'encrypted',
            'attempt_number' => 'integer',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<MockResponse, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(MockResponse::class, 'response_id');
    }

    /** @return BelongsTo<CallbackAttempt, $this> */
    public function resendOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resend_of_id');
    }
}
