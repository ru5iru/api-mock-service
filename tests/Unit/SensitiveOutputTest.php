<?php

namespace Tests\Unit;

use App\Services\Curl\RequestCredentialPolicy;
use App\Services\Mcp\McpAuditRedactor;
use Tests\TestCase;

final class SensitiveOutputTest extends TestCase
{
    public function test_logged_url_masks_only_configured_query_values_without_reencoding_other_parts(): void
    {
        config(['mock.portable_config.sensitive_query_keys' => ['token', 'custom_key']]);
        $url = 'http://localhost/a%2Fb?ref=visible&session=visible&%74oken=CANARY-ONE&token=CANARY-TWO&custom_key=CANARY-THREE&token=REPLACE_ME&empty=&flag#token=fragment';
        self::assertSame('http://localhost/a%2Fb?ref=visible&session=visible&%74oken=[REDACTED]&token=[REDACTED]&custom_key=[REDACTED]&token=REPLACE_ME&empty=&flag#token=fragment', app(RequestCredentialPolicy::class)->maskUrl($url));
        self::assertSame('http://localhost/path#?token=fragment', app(RequestCredentialPolicy::class)->maskUrl('http://localhost/path#?token=fragment'));
    }

    public function test_mcp_curl_text_reuses_header_heuristics_and_recursive_json_redaction(): void
    {
        $curl = <<<'CURL'
curl 'http://localhost/path?token=CANARY-QUERY&ref=visible' -H 'X-Internal-Token: CANARY-HEADER' --data '{"nested":{"password":"CANARY-BODY"},"ref":"visible"}'
CURL;
        $safe = app(McpAuditRedactor::class)->redact($curl);
        foreach (['CANARY-HEADER', 'CANARY-BODY', 'CANARY-QUERY'] as $canary) {
            self::assertStringNotContainsString($canary, $safe);
        }
        self::assertStringContainsString('visible', $safe);
        self::assertStringContainsString('X-Internal-Token:', $safe);
        self::assertSame('[REDACTED]', app(McpAuditRedactor::class)->redact("curl --header 'unterminated CANARY-SECRET"));
    }

    public function test_mcp_form_fields_use_the_existing_sensitive_name_policy(): void
    {
        $curl = "curl http://localhost/form --data 'password=CANARY-FORM&ref=visible&%74oken=CANARY-TOKEN&password=CANARY-REPEAT'";
        $safe = app(McpAuditRedactor::class)->redact($curl);
        foreach (['CANARY-FORM', 'CANARY-TOKEN', 'CANARY-REPEAT'] as $canary) {
            self::assertStringNotContainsString($canary, $safe);
        }
        self::assertStringContainsString('ref=visible', $safe);
        self::assertStringContainsString('%74oken=[REDACTED]', $safe);
        $canonical = "POST\n/form\n\npassword=CANARY-FORM&ref=visible";
        self::assertSame("POST\n/form\n\npassword=[REDACTED]&ref=visible", app(McpAuditRedactor::class)->redact($canonical));
    }
}
