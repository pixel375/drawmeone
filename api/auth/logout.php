<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/auth.php';
dmo_auth_require_enabled();
dmo_auth_json_request();
try {
    $db = dmo_database();
    $session = dmo_auth_current_session($db);
    if ($session !== null) {
        dmo_auth_verify_csrf($session);
        $db->prepare('DELETE FROM dmo_sessions WHERE id = :id')
            ->execute(['id' => $session['session_id']]);
    }
    dmo_auth_cookie('', time() - 3600);
    dmo_json(200, ['ok' => true, 'status' => 'signed_out']);
} catch (Throwable $error) {
    dmo_log_problem($error, 'logout');
    dmo_json(503, ['ok' => false, 'error' => 'account_service_unavailable']);
}
