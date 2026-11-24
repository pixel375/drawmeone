<?php
declare(strict_types=1);

// CI-only localhost router. Simulated HTTPS is NEVER enabled on Hostinger.
// This file is not an HTTP entry point in normal production deployments.
if (PHP_SAPI !== 'cli-server' ||
    getenv('DMO_CI_INTEGRATION') !== '1' ||
    getenv('DB_NAME') !== 'drawmeone_ci' ||
    ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(404);
    exit;
}
$uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$allowed = [
    '/api/request.php',
    '/api/request-message.php',
    '/api/quote-response.php',
    '/api/admin/overview.php',
    '/api/admin/order-create.php',
];
if (!in_array($uri, $allowed, true)) {
    http_response_code(404);
    exit;
}
// Only the in-process disposable local server may emulate HTTPS.
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
require dirname(__DIR__) . $uri;
