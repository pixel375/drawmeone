<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/commission-workflow.php';
dmo_reject_non_get();

try {
    $db = dmo_database();
    dmo_admin_require_session($db);
    $id = dmo_workflow_id($_GET['id'] ?? null);
    $detail = dmo_workflow_load($db, $id);
    if ($detail === null) {
        dmo_json(404, ['ok' => false, 'error' => 'request_not_found']);
    }
    dmo_json(200, [
        'ok' => true,
        'detail' => $detail,
        'admin_writes_enabled' => dmo_workflow_writes_enabled(),
        'quote_sending_enabled' => false,
        'customer_messaging_enabled' => dmo_workflow_messaging_enabled() &&
            dmo_messages_request_open((string) $detail['request']['status']),
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin commission detail');
    dmo_json(503, ['ok' => false, 'error' => 'admin_service_unavailable']);
}
