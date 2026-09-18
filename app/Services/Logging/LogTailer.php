<?php

namespace App\Services\Logging;

/**
 * Reads bounded tails across rotating mock-request JSON logs. It never loads a
 * complete log into memory and tolerates malformed or partially written lines.
 */
final class LogTailer
{
    /** @return list<array<string, mixed>> */
    public function recent(?int $lineLimit = null): array
    {
        $files = glob(storage_path('logs/mock-requests*.log')) ?: [];
        usort($files, static function (string $left, string $right): int {
            $modified = (filemtime($right) ?: 0) <=> (filemtime($left) ?: 0);

            return $modified !== 0 ? $modified : strcmp(basename($right), basename($left));
        });

        if ($files === []) {
            return [];
        }

        $limit = $lineLimit ?? (int) config('mock.log_tail_lines', 100);
        $limit = max(1, $limit);
        $remainingBytes = max(4096, (int) config('mock.log_tail_max_bytes', 524288));
        $events = [];

        foreach ($files as $file) {
            if ($remainingBytes <= 0 || count($events) >= $limit) {
                break;
            }

            [$lines, $bytesRead] = $this->tailFile($file, $remainingBytes);
            $remainingBytes -= $bytesRead;

            foreach (array_reverse($lines) as $line) {
                $event = json_decode($line, true);
                if (is_array($event)) {
                    $events[] = $event;
                }

                if (count($events) >= $limit) {
                    break 2;
                }
            }
        }

        return $events;
    }

    /** @return array{list<string>, int} */
    private function tailFile(string $file, int $maxBytes): array
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            return [[], 0];
        }

        try {
            $size = filesize($file) ?: 0;
            $bytesRead = min($size, $maxBytes);
            $start = max(0, $size - $bytesRead);
            fseek($handle, $start);
            if ($start > 0) {
                fgets($handle);
            }
            $contents = stream_get_contents($handle) ?: '';
        } finally {
            fclose($handle);
        }

        return [array_values(array_filter(explode("\n", trim($contents)))), $bytesRead];
    }
}
