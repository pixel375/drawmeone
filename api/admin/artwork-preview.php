<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/artwork.php';
dmo_reject_non_get();
try {
    $db = dmo_database();
    dmo_admin_require_session($db);
    $id = dmo_artwork_id($_GET['id'] ?? null);
    $variant = $_GET['v'] ?? 'thumb';
    if (!is_string($variant)) {
        http_response_code(404);
        exit;
    }
    dmo_artwork_send_file($db, $id, $variant, true);
} catch (Throwable $error) {
    dmo_log_problem($error, 'private artwork preview');
    http_response_code(404);
    exit;
}
