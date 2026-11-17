<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/admin.php';
dmo_reject_non_get();
dmo_admin_require_ready();

try {
    $session = dmo_admin_current_session(dmo_database());
    if ($session === null) {
        dmo_json(200, ['ok' => true, 'authenticated' => false]);
    }
    dmo_json(200, [
        'ok' => true,
        'authenticated' => true,
        'admin' => [
            'display_name' => $session['display_name'],
            'email' => $session['email'],
        ],
        'csrf_token' => dmo_auth_csrf($session['session_hash']),
        'session_policy' => [
            'absolute_hours' => 12,
            'idle_minutes' => 30,
        ],
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin me');
    dmo_json(503, ['ok' => false, 'error' => 'admin_service_unavailable']);
}
