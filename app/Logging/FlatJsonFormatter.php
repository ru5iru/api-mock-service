<?php

namespace App\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Formats the dedicated mock-request channel as one flat JSON object per line.
 */
final class FlatJsonFormatter implements FormatterInterface
{
    public function format(LogRecord $record): string
    {
        $payload = [
            'timestamp' => $record->datetime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            ...$record->context,
            'level' => strtolower($record->level->getName()),
        ];

        if ($record->message !== 'mock_request') {
            $payload['message'] = $record->message;
        }

        return json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        )."\n";
    }

    /** @param array<LogRecord> $records */
    public function formatBatch(array $records): string
    {
        return implode('', array_map($this->format(...), $records));
    }
}
