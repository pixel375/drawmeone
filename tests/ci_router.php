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
    '/api/requests.php',
    '/api/request-message.php',
    '/api/request-withdraw.php',
    '/api/quote-response.php',
    '/api/admin/overview.php',
    '/api/admin/finance.php',
    '/api/admin/customers.php',
    '/api/site-settings.php',
    '/api/admin/design.php',
    '/api/admin/packages.php',
    '/api/catalog.php',
    '/api/admin/intake.php',
    '/api/request-status.php',
    '/api/policy.php',
    '/policy.php',
    '/services.php',
    '/api/admin/messages.php',
    '/api/admin/requests.php',
    '/api/admin/order-create.php',
    '/api/admin/delivery-draft.php',
    '/api/delivery.php',
    '/api/checkout.php',
    '/api/artwork-review.php',
    '/api/admin/request.php',
    '/api/admin/request-actions.php',
    '/api/admin/quote-send.php',
    '/api/admin/artwork-upload.php',
    '/api/admin/artwork-preview.php',
    '/api/admin/artworks.php',
    '/api/portfolio.php',
    '/api/artwork.php',
    '/artwork.php',
    '/portfolio.php',
    '/sitemap.php',
    '/api/auth/verify.php',
    '/api/auth/reset-confirm.php',
    '/api/auth/me.php',
    '/api/auth/logout.php',
    '/api/auth/security.php',
    '/api/auth/privacy.php',
    '/api/admin/privacy.php',
    '/api/request-attachment.php',
    '/api/reference.php',
    '/api/reference-delete.php',
];
if (!in_array($uri, $allowed, true)) {
    http_response_code(404);
    exit;
}
// Only the in-process disposable local server may emulate HTTPS.
$_SERVER['HTTPS'] = 'on';
$_SERVER['SERVER_PORT'] = '443';
require dirname(__DIR__) . $uri;
