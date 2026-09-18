<?php

namespace Tests\Unit;

use App\Services\Logging\LogTailer;
use Tests\TestCase;

final class LogTailerTest extends TestCase
{
    public function test_it_reads_newest_events_across_rotated_files_and_skips_malformed_lines(): void
    {
        $directory = storage_path('logs');
        $older = $directory.'/mock-requests-2099-12-30.log';
        $newer = $directory.'/mock-requests-2099-12-31.log';
        @mkdir($directory, 0777, true);

        file_put_contents($older, "{\"sequence\":1}\n{\"sequence\":2}\n");
        file_put_contents($newer, "{\"sequence\":3}\nnot-json\n{\"sequence\":4}\n");
        touch($older, 4102358400);
        touch($newer, 4102444800);

        try {
            $events = (new LogTailer)->recent(3);

            self::assertSame([4, 3, 2], array_column($events, 'sequence'));
        } finally {
            @unlink($older);
            @unlink($newer);
        }
    }
}
