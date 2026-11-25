<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/auth.php';
dmo_reject_non_get();
dmo_auth_require_enabled();
try {
    $db=dmo_database();
    $session = dmo_auth_current_session($db);
    if ($session === null || $session['role'] !== 'customer') {
        dmo_json(200, ['ok' => true, 'authenticated' => false]);
    }
    $devices=$db->prepare('SELECT COUNT(*) FROM dmo_sessions WHERE user_id=:id AND expires_at>UTC_TIMESTAMP()');
    $devices->execute(['id'=>$session['user_id']]);
    dmo_json(200, [
        'ok' => true,
        'authenticated' => true,
        'active_sessions' => (int)$devices->fetchColumn(),
        'user' => [
            'display_name' => $session['display_name'],
            'email' => $session['email'],
            'role' => 'customer',
        ],
        'csrf_token' => dmo_auth_csrf($session['session_hash']),
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'current session');
    dmo_json(503, ['ok' => false, 'error' => 'account_service_unavailable']);
}
