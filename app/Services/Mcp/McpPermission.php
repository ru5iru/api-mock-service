<?php

namespace App\Services\Mcp;

enum McpPermission: string
{
    case Read = 'read';
    case Write = 'write';
    case Verify = 'verify';
    case Cleanup = 'cleanup';
}
