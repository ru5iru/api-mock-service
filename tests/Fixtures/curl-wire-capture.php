<?php

// Test-only HTTP receiver: inspect curl's actual method, headers and body bytes.
header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => getallheaders(),
    'body' => base64_encode(file_get_contents('php://input')),
], JSON_THROW_ON_ERROR);
