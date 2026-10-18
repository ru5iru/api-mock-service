<?php

namespace App\Services\Mcp;

enum McpErrorCode: string
{
    case ValidationError = 'VALIDATION_ERROR';
    case AuthError = 'AUTH_ERROR';
    case Forbidden = 'FORBIDDEN';
    case Conflict = 'CONFLICT';
    case RunNotFound = 'RUN_NOT_FOUND';
    case RunFinished = 'RUN_FINISHED';
    case AssertionFailed = 'ASSERTION_FAILED';
    case InconclusiveEvidence = 'INCONCLUSIVE_EVIDENCE';
    case IdempotencyConflict = 'IDEMPOTENCY_CONFLICT';
    case NotFound = 'NOT_FOUND';
}
