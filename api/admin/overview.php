<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/admin.php';
dmo_reject_non_get();

try {
    $db = dmo_database();
    dmo_admin_require_session($db);
    $queries = [
        'requests_for_review' => "SELECT COUNT(*) FROM dmo_commission_requests " .
            "WHERE status IN ('submitted','clarification_needed')",
        'active_orders' => "SELECT COUNT(*) FROM dmo_orders " .
            "WHERE status IN ('queued','drawing','awaiting_feedback','revision')",
        'payments_to_verify' => "SELECT COUNT(*) FROM dmo_payments " .
            "WHERE status = 'pending_verification'",
        'messages_last_seven_days' => "SELECT COUNT(*) FROM dmo_messages " .
            "WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)",
    ];
    $counts = [];
    foreach ($queries as $name => $sql) {
        $counts[$name] = (int) $db->query($sql)->fetchColumn();
    }
    dmo_json(200, [
        'ok' => true,
        'counts' => $counts,
        'readonly' => true,
        'data_is_live' => true,
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin overview');
    dmo_json(503, ['ok' => false, 'error' => 'admin_service_unavailable']);
}
