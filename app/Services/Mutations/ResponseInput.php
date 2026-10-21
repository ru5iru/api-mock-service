<?php

namespace App\Services\Mutations;

final readonly class ResponseInput
{
    public function __construct(
        public int $statusCode = 200,
        public string $headersJson = "{\n  \"Content-Type\": \"application/json\"\n}",
        public string $body = "{\n  \"ok\": true\n}",
        public string $bodyMode = 'static',
        public string $template = "{\n  \"\": \"\"\n}",
        public string $editorView = 'builder',
        public string $seedMode = 'random',
        public ?int $seed = null,
        public string $locale = 'en',
        public int $delayMs = 0,
        public int $weight = 1,
        public bool $faultEnabled = false,
        public string $faultType = 'delay',
        public int $faultDelayMsMin = 0,
        public ?int $faultDelayMsMax = null,
        public int $faultProbability = 100,
        public bool $callbackEnabled = false,
        public string $callbackUrl = '',
        public string $callbackMethod = 'POST',
        public string $callbackHeadersJson = "{\n  \"Content-Type\": \"application/json\"\n}",
        public string $callbackBody = "{\n  \"event\": \"created\"\n}",
        public int $callbackDelayMs = 0,
        public ?int $callbackDelayMaxMs = null,
        public int $callbackRetry = 1,
        public int $callbackBackoffMs = 1000,
        public int $callbackTimeoutMs = 5000,
        public bool $callbackSigningEnabled = false,
        public string $callbackSigningSecret = '',
        public string $callbackSignatureHeader = 'X-MockDeck-Signature',
        public bool $callbackSecretSet = false,
    ) {}

    public function values(): array
    {
        return get_object_vars($this);
    }
}
