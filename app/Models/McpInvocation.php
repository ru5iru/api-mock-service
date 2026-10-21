<?php

namespace App\Models;

use App\Services\Mcp\McpAuditRedactor;
use App\Services\Mcp\McpErrorCode;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Schema/model only in Phase 1. Tool handlers begin recording invocations in Phase 3. */
final class McpInvocation extends Model
{
    public $timestamps = false;

    protected $fillable = ['credential_id', 'tool_name', 'target', 'run_id', 'result', 'error_code', 'correlation_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'error_code' => McpErrorCode::class];
    }

    protected function target(): Attribute
    {
        return Attribute::make(set: static fn (?string $value): ?string => $value === null ? null : app(McpAuditRedactor::class)->redact($value));
    }

    public function credential(): BelongsTo
    {
        return $this->belongsTo(McpCredential::class);
    }
}
