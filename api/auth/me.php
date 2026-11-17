<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/auth.php';
dmo_reject_non_get();
dmo_auth_require_enabled();
try {
    $session = dmo_auth_current_session(dmo_database());
    if ($session === null || $session['role'] !== 'customer') {
        dmo_json(200, ['ok' => true, 'authenticated' => false]);
    }
    dmo_json(200, [
        'ok' => true,
        'authenticated' => true,
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
