<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/references.php';

dmo_reject_non_get();
try {
    $db = dmo_database();
    $admin = dmo_admin_enabled() ? dmo_admin_current_session($db) : null;
    $customer = $admin === null && dmo_auth_configured()
        ? dmo_auth_current_session($db) : null;
    if ($admin === null &&
        ($customer === null || ($customer['role'] ?? '') !== 'customer')) {
        dmo_json(401, ['ok' => false, 'error' => 'account_required']);
    }
    if (!dmo_refs_schema_ready($db)) {
        dmo_json(404, ['ok' => false, 'error' => 'reference_not_found']);
    }
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $variant = $_GET['variant'] ?? 'thumb';
    if ($id === false || !is_int($id) ||
        !is_string($variant) || !in_array($variant, ['thumb','display'], true)) {
        dmo_json(404, ['ok' => false, 'error' => 'reference_not_found']);
    }
    $stmt = $db->prepare(
        'SELECT a.storage_key,a.mime_type,a.byte_size,a.pixel_width,a.pixel_height,r.customer_id FROM dmo_request_attachments a ' .
        'JOIN dmo_commission_requests r ON r.id = a.request_id ' .
        'WHERE a.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($item) || ($admin === null &&
        (int) $item['customer_id'] !== (int) $customer['user_id'])) {
        dmo_json(404, ['ok' => false, 'error' => 'reference_not_found']);
    }
    if(!dmo_refs_variant_valid($item,$variant)){
        dmo_json(404, ['ok' => false, 'error' => 'reference_not_found']);
    }
    $file=dmo_refs_storage_file((string)$item['storage_key'],$variant);
    header('Content-Type: image/webp');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: private, no-store, max-age=0');
    header('Content-Disposition: inline');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Referrer-Policy: no-referrer');
    header('Content-Security-Policy: default-src ' . "'none'");
    readfile($file);
    exit;
} catch (Throwable $error) {
    dmo_log_problem($error, 'private reference access');
    dmo_json(503, ['ok' => false, 'error' => 'reference_service_unavailable']);
}
