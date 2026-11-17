<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/admin.php';
dmo_reject_non_get();

try {
    $db = dmo_database();
    dmo_admin_require_session($db);
    // Administrative data must never be accessible without a verified admin
    // role. This endpoint excludes private attachment storage keys.
    $stmt = $db->query(
        'SELECT r.id, r.public_ref, r.subject_type, r.status, ' .
        'r.preferred_contact, r.submitted_at, LEFT(r.brief, 500) AS brief_excerpt, ' .
        'u.display_name AS customer_name, t.name AS tier_name ' .
        'FROM dmo_commission_requests r ' .
        'JOIN dmo_users u ON u.id = r.customer_id ' .
        'LEFT JOIN dmo_commission_tiers t ON t.id = r.tier_id ' .
        'ORDER BY r.submitted_at DESC, r.id DESC LIMIT 40'
    );
    dmo_json(200, [
        'ok' => true,
        'requests' => $stmt->fetchAll(),
        'readonly' => true,
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin requests');
    dmo_json(503, ['ok' => false, 'error' => 'admin_service_unavailable']);
}
