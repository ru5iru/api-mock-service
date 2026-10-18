<?php

namespace App\Services\Mcp;

use App\Models\McpCredential;
use JsonSerializable;
use LogicException;

/** The only object carrying plaintext; it cannot serialize or reveal it twice. */
final class IssuedMcpCredential implements JsonSerializable
{
    public function __construct(public readonly McpCredential $credential, #[\SensitiveParameter] private ?string $token) {}

    private function __clone() {}

    public function reveal(): string
    {
        $token = $this->token ?? throw new LogicException('The token has already been shown. Generate a replacement credential.');
        $this->token = null;

        return $token;
    }

    public function jsonSerialize(): array
    {
        return $this->credential->toArray();
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        throw new LogicException('Issued credentials cannot be serialized.');
    }
}
