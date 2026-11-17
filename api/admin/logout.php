<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/admin.php';
dmo_admin_require_ready();
dmo_auth_json_request();

try {
    $db = dmo_database();
    $session = dmo_admin_current_session($db);
    if ($session !== null) {
        dmo_auth_verify_csrf($session);
        $db->prepare('DELETE FROM dmo_sessions WHERE id = :id')
            ->execute(['id' => $session['session_id']]);
        dmo_admin_audit($db, (int) $session['user_id'], 'admin.logout');
    }
    dmo_admin_cookie('', time() - 3600);
    dmo_json(200, ['ok' => true, 'status' => 'signed_out']);
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin logout');
    dmo_json(503, ['ok' => false, 'error' => 'admin_service_unavailable']);
}
