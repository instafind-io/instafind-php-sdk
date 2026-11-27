<?php

declare(strict_types=1);

// Router for `php -S`: echoes the request back as JSON, or misbehaves on demand.

/** @var string $uri */
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
$ms = $_GET['ms'] ?? '500';
$code = $_GET['code'] ?? '500';

if ($path === '/slow') {
    usleep((is_numeric($ms) ? (int) $ms : 500) * 1000);
}
if ($path === '/redirect') {
    header('Location: /elsewhere', true, 302);
    exit;
}
if ($path === '/status') {
    http_response_code(is_numeric($code) ? (int) $code : 500);
    header('Retry-After: 7');
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with($key, 'HTTP_')) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
}
if (isset($_SERVER['CONTENT_TYPE'])) {
    $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
}

header('Content-Type: application/json');
header('X-Echo: yes');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $uri,
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
]);
