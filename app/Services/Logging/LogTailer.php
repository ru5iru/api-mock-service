<?php

namespace App\Services\Logging;

/**
 * Reads a bounded tail of the newest rotating mock-request JSON log for the
 * dashboard. It never loads the complete log into memory.
 */
final class LogTailer
{
    /** @return list<array<string, mixed>> */
    public function recent(?int $lineLimit = null): array
    {
        $files = glob(storage_path('logs/mock-requests*.log')) ?: [];
        usort($files, static fn (string $left, string $right): int => filemtime($right) <=> filemtime($left));

        if ($files === []) {
            return [];
        }

        $maxBytes = max(4096, (int) config('mock.log_tail_max_bytes', 524288));
        $handle = fopen($files[0], 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $size = filesize($files[0]) ?: 0;
            $start = max(0, $size - $maxBytes);
            fseek($handle, $start);
            if ($start > 0) {
                fgets($handle);
            }
            $contents = stream_get_contents($handle) ?: '';
        } finally {
            fclose($handle);
        }

        $lines = array_values(array_filter(explode("\n", trim($contents))));
        $limit = $lineLimit ?? (int) config('mock.log_tail_lines', 100);
        $lines = array_slice($lines, -max(1, $limit));

        $events = [];
        foreach (array_reverse($lines) as $line) {
            $event = json_decode($line, true);
            if (is_array($event)) {
                $events[] = $event;
            }
        }

        return $events;
    }
}
