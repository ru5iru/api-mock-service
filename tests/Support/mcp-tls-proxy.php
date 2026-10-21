<?php

// Disposable test-only TLS terminator; verifies client trust without external services.
$context = stream_context_create(['ssl' => ['local_cert' => $argv[3], 'local_pk' => $argv[4], 'verify_peer' => false]]);
$listener = stream_socket_server('tls://127.0.0.1:'.$argv[1], $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($listener === false) {
    throw new RuntimeException($error);
}
while (true) {
    $client = @stream_socket_accept($listener, 5);
    if ($client === false) {
        continue;
    }
    stream_set_timeout($client, 15);
    $headers = '';
    while (($line = fgets($client)) !== false) {
        $headers .= $line;
        if ($line === "\r\n") {
            break;
        }
    }
    preg_match('/^Content-Length:\s*(\d+)/mi', $headers, $length);
    $body = '';
    while (strlen($body) < (int) ($length[1] ?? 0)) {
        $chunk = fread($client, (int) $length[1] - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }
    $headers = preg_replace('/^Connection:[^\r\n]*\r\n/mi', '', $headers);
    $headers = substr($headers, 0, -2)."Connection: close\r\n\r\n";
    $upstream = stream_socket_client('tcp://127.0.0.1:'.$argv[2], $errno, $error, 15);
    if ($upstream === false) {
        fclose($client);

        continue;
    }
    fwrite($upstream, $headers.$body);
    stream_set_timeout($upstream, 15);
    while (! feof($upstream)) {
        $chunk = fread($upstream, 65536);
        if ($chunk === false || $chunk === '') {
            break;
        }
        fwrite($client, $chunk);
    }
    fclose($upstream);
    fclose($client);
}
