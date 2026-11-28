<?php

namespace Tests\Unit;

use App\Services\Callbacks\CallbackDelivery;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Unit tests for CallbackDelivery::validateUrl().
 *
 * validateUrl() returns void and throws InvalidArgumentException on a blocked or
 * malformed URL, so the helper below wraps it into a bool for concise assertions.
 *
 * CallbackDelivery has final-readonly constructor dependencies that are unneeded
 * by validateUrl(). ReflectionClass::newInstanceWithoutConstructor() bypasses the
 * constructor safely because validateUrl() never reads injected properties.
 *
 * Closes R2-02. Strengthens confidence in the R1-02 partial fix.
 */
final class CallbackUrlValidationTest extends TestCase
{
    private function validate(string $url): bool
    {
        /** @var CallbackDelivery $delivery */
        $delivery = (new ReflectionClass(CallbackDelivery::class))->newInstanceWithoutConstructor();

        try {
            $delivery->validateUrl($url);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    // ── Blocked: private / reserved IPv4 ──────────────────────────────────

    public function test_private_ipv4_class_a_is_blocked(): void
    {
        self::assertFalse($this->validate('http://10.0.0.1/hook'));
    }

    public function test_private_ipv4_class_b_is_blocked(): void
    {
        self::assertFalse($this->validate('https://172.16.0.1/hook'));
    }

    public function test_private_ipv4_class_c_is_blocked(): void
    {
        self::assertFalse($this->validate('https://192.168.1.1/hook'));
    }

    public function test_loopback_ipv4_is_blocked(): void
    {
        self::assertFalse($this->validate('http://127.0.0.1/hook'));
        self::assertFalse($this->validate('http://127.0.0.1:9999/hook'));
    }

    // ── Blocked: IPv6 ─────────────────────────────────────────────────────

    public function test_ipv6_loopback_is_blocked(): void
    {
        self::assertFalse($this->validate('http://[::1]/hook'));
    }

    public function test_ipv6_unique_local_fc00_range_is_blocked(): void
    {
        self::assertFalse($this->validate('http://[fc00::1]/hook'));
        self::assertFalse($this->validate('http://[fd12:3456:789a::1]/hook'));
    }

    // ── Allowed: public addresses ─────────────────────────────────────────

    public function test_public_https_url_passes(): void
    {
        self::assertTrue($this->validate('https://hooks.example.com/endpoint'));
    }

    public function test_public_http_url_passes(): void
    {
        self::assertTrue($this->validate('http://hooks.example.com/endpoint'));
    }

    public function test_public_ipv4_passes(): void
    {
        self::assertTrue($this->validate('https://203.0.113.5/hook'));
    }

    // ── Hostname targets (documented scope limit) ─────────────────────────

    /**
     * Hostname-based targets are NOT DNS-resolved by design.
     * Egress firewall rules are the stated control for hostname SSRF.
     * See CallbackDelivery::validateUrl() inline comment.
     */
    public function test_hostname_url_passes_without_dns_resolution(): void
    {
        self::assertTrue($this->validate('https://internal.corp/hook'));
    }

    // ── Malformed inputs ─────────────────────────────────────────────────

    public function test_bare_string_is_blocked(): void
    {
        self::assertFalse($this->validate('not-a-url'));
    }

    public function test_empty_string_is_blocked(): void
    {
        self::assertFalse($this->validate(''));
    }

    public function test_javascript_scheme_is_blocked(): void
    {
        self::assertFalse($this->validate('javascript:alert(1)'));
    }

    public function test_file_scheme_is_blocked(): void
    {
        self::assertFalse($this->validate('file:///etc/passwd'));
    }
}
