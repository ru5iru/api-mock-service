<?php

namespace Tests\Unit;

use App\Models\MockResponse;
use App\Services\Response\FaultConfigurationValidator;
use App\Services\Response\FaultInjectionService;
use App\Services\Response\FaultPlan;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class FaultInjectionTest extends TestCase
{
    public function test_disabled_fault_and_zero_probability_preserve_arbitrary_body_bytes(): void
    {
        $faults = new FaultInjectionService;
        $body = "\x00raw\xff bytes\r\n";
        foreach ([['fault_enabled' => false], ['fault_enabled' => true, 'fault_probability' => 0]] as $attributes) {
            $response = new MockResponse([...FaultConfigurationValidator::defaults(), ...$attributes, 'fault_type' => 'truncated_body']);
            $plan = $faults->decide($response);
            self::assertNull($plan);
            self::assertSame($body, $faults->transformBody($body, $plan, 'application/octet-stream'));
        }
    }

    public function test_certain_probability_always_applies_and_delay_range_stays_inclusive(): void
    {
        $faults = new FaultInjectionService;
        $response = new MockResponse([...FaultConfigurationValidator::defaults(), 'fault_enabled' => true, 'fault_delay_ms_min' => 12, 'fault_delay_ms_max' => 16]);
        for ($i = 0; $i < 20; $i++) {
            $plan = $faults->decide($response);
            self::assertSame('delay', $plan->type);
            self::assertGreaterThanOrEqual(12, $plan->delayMs);
            self::assertLessThanOrEqual(16, $plan->delayMs);
        }
        $response->fault_delay_ms_max = null;
        self::assertSame(12, $faults->decide($response)->delayMs);
    }

    public function test_delay_is_measurable_and_leaves_the_body_unchanged(): void
    {
        $faults = new FaultInjectionService;
        $plan = new FaultPlan('delay', 20);
        $start = hrtime(true);
        $faults->pause($plan);
        self::assertGreaterThanOrEqual(19, (hrtime(true) - $start) / 1_000_000);
        self::assertSame('{"ok":true}', $faults->transformBody('{"ok":true}', $plan, 'application/json'));
    }

    public function test_timeout_is_only_a_bounded_delay_and_connection_reset_is_not_a_supported_type(): void
    {
        $faults = new FaultInjectionService;
        $response = new MockResponse([...FaultConfigurationValidator::defaults(), 'fault_enabled' => true, 'fault_type' => 'timeout', 'fault_delay_ms_min' => 60000]);
        self::assertSame(60000, $faults->decide($response)->delayMs);
        $response->fault_delay_ms_min = 999999;
        self::assertSame(120000, $faults->decide($response)->delayMs);
        $response->fault_type = 'connection_reset';
        self::assertNull($faults->decide($response));
        self::assertNotContains('connection_reset', FaultConfigurationValidator::TYPES);
    }

    public function test_malformed_json_and_xml_have_exact_invalid_payloads_and_truncation_is_in_bytes(): void
    {
        $faults = new FaultInjectionService;
        $malformed = new FaultPlan('malformed_body');
        foreach (['application/json', 'Application/Problem+JSON; charset=utf-8'] as $type) {
            $body = $faults->transformBody('{"ok":true}', $malformed, $type);
            self::assertSame('{"mockdeck_fault":', $body);
            json_decode($body);
            self::assertNotSame(JSON_ERROR_NONE, json_last_error());
        }
        self::assertSame('<mockdeck-fault>', $faults->transformBody('<ok/>', $malformed, 'application/xml'));
        self::assertSame('<mockdeck-fault>', $faults->transformBody('<ok/>', $malformed, 'image/svg+xml'));
        $body = '{"message":"hello world","ok":true}';
        $truncated = $faults->transformBody($body, new FaultPlan('truncated_body'), 'application/json');
        self::assertSame(substr($body, 0, intdiv(strlen($body), 2)), $truncated);
        json_decode($truncated);
        self::assertNotSame(JSON_ERROR_NONE, json_last_error());
        self::assertSame("\xc3", $faults->transformBody('éx', new FaultPlan('truncated_body'), 'text/plain'));
        self::assertSame('', $faults->transformBody('', new FaultPlan('truncated_body'), 'text/plain'));
    }

    public function test_validator_rejects_invalid_probability_range_boolean_and_unsupported_types(): void
    {
        $validator = new FaultConfigurationValidator;
        foreach ([
            ['fault_probability' => -1], ['fault_probability' => 101], ['fault_probability' => 0.5],
            ['fault_enabled' => 'yes'], ['fault_type' => 'connection_reset'],
            ['fault_delay_ms_min' => -1], ['fault_delay_ms_max' => 120001],
            ['fault_delay_ms_min' => 12, 'fault_delay_ms_max' => 11],
        ] as $attributes) {
            try {
                $validator->validate($attributes);
                self::fail('Invalid fault configuration was accepted: '.json_encode($attributes));
            } catch (ValidationException $exception) {
                self::assertNotEmpty($exception->errors());
            }
        }
        self::assertSame(FaultConfigurationValidator::defaults(), $validator->validate([]));
        self::assertSame(FaultConfigurationValidator::defaults(), $validator->validate(array_fill_keys(array_keys(FaultConfigurationValidator::defaults()), null)));
        self::assertSame(FaultConfigurationValidator::defaults(), $validator->attributes(['body' => 'unrelated', 'fault_enabled' => 0]));
    }

    public function test_malformed_body_validation_matches_declared_content_type_and_template_default(): void
    {
        $validator = new FaultConfigurationValidator;
        $settings = ['fault_enabled' => true, 'fault_type' => 'malformed_body'];
        foreach ([['body_mode' => 'template'], ['headers' => ['content-type' => 'APPLICATION/PROBLEM+JSON; charset=UTF-8']], ['headers' => ['Content-Type' => 'text/xml']]] as $response) {
            self::assertSame('malformed_body', $validator->validate([...$settings, ...$response])['fault_type']);
        }
        $this->expectException(ValidationException::class);
        $validator->validate([...$settings, 'headers' => ['Content-Type' => 'text/plain']]);
    }
}
