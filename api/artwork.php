<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/artwork.php';
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex');
dmo_reject_non_get();

try {
    $id = dmo_artwork_id($_GET['id'] ?? null);
    $variant = $_GET['v'] ?? 'display';
    if (!is_string($variant)) {
        http_response_code(404);
        exit;
    }
    dmo_artwork_send_file(dmo_database(), $id, $variant, false);
} catch (Throwable $error) {
    dmo_log_problem($error, 'public artwork image');
    http_response_code(404);
    exit;
}
