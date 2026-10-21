<?php

// Lightweight real TCP client: all workers wait at one barrier before sending.
$deadline = microtime(true) + 20;
while (! file_exists($argv[1])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Concurrent client barrier timed out.');
    }
    usleep(1000);
}
$headers = "Content-Type: application/json\r\n";
if (($argv[3] ?? '') !== '') {
    $headers .= 'X-MockDeck-Test-Run: '.$argv[3]."\r\n";
}
$context = stream_context_create(['http' => ['method' => 'POST', 'header' => $headers,
    'content' => '{"same":"body"}', 'ignore_errors' => true, 'timeout' => 45]]);
$body = file_get_contents($argv[2], false, $context);
preg_match('/HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $status);
echo json_encode(['status' => (int) ($status[1] ?? 0), 'body' => json_decode($body ?: '', true)], JSON_THROW_ON_ERROR);
