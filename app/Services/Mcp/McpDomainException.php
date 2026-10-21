<?php

namespace App\Services\Mcp;

use RuntimeException;

final class McpDomainException extends RuntimeException
{
    public function __construct(public readonly McpErrorCode $errorCode, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
