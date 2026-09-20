<?php

namespace Tests\Unit;

use App\Services\Config\ConfigValidator;
use Tests\TestCase;

final class ConfigValidatorTest extends TestCase
{
    public function test_future_format_versions_are_rejected(): void
    {
        $result = app(ConfigValidator::class)->validate(json_encode([
            'format' => 'mockdeck',
            'format_version' => 99,
            'endpoints' => [],
        ], JSON_THROW_ON_ERROR));

        self::assertFalse($result->valid());
        self::assertStringContainsString('not supported', implode(' ', $result->errors));
    }

    public function test_unknown_fields_warn_without_becoming_imported_data(): void
    {
        $result = app(ConfigValidator::class)->validate(json_encode([
            'format' => 'mockdeck',
            'format_version' => 1,
            'unexpected' => 'ignored',
            'endpoints' => [],
        ], JSON_THROW_ON_ERROR));

        self::assertTrue($result->valid());
        self::assertStringContainsString('unknown', implode(' ', $result->warnings));
    }
}
