<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/admin.php';
dmo_admin_require_ready();
$input = dmo_auth_json_request();

$email = dmo_auth_email(dmo_auth_field($input, 'email', 254));
$password = $input['password'] ?? '';
$captcha = dmo_auth_field($input, 'turnstile_token', 2048);
if (!is_string($password) || strlen($password) > 128) {
    dmo_json(422, ['ok' => false, 'error' => 'invalid_input']);
}

try {
    $db = dmo_database();
    if (!dmo_auth_rate_limit($db, 'admin-login-ip', '', 8, 15) ||
        !dmo_auth_rate_limit($db, 'admin-login-email', $email, 5, 15)) {
        dmo_json(429, ['ok' => false, 'error' => 'too_many_attempts']);
    }
    if (!dmo_auth_verify_turnstile($captcha, 'admin_login')) {
        dmo_json(422, ['ok' => false, 'error' => 'verification_failed']);
    }

    $stmt = $db->prepare(
        'SELECT id, password_hash, role, account_status, email_verified_at ' .
        'FROM dmo_users WHERE email = :email LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $record = $stmt->fetch();
    // Perform a password hash check even for an absent account.
    $dummy = '$2y$12$GoZeZg6Kj6baWePuXK8DQek3wK81b.wKK62JysP1B6bAFMFp9s1H6';
    $valid = password_verify(
        $password, is_array($record) ? $record['password_hash'] : $dummy
    );
    if (!$valid || !is_array($record)
        || $record['role'] !== 'admin'
        || $record['account_status'] !== 'active'
        || $record['email_verified_at'] === null) {
        dmo_json(401, ['ok' => false, 'error' => 'invalid_credentials']);
    }

    $db->beginTransaction();
    try {
        dmo_admin_issue_session($db, (int) $record['id']);
        dmo_admin_audit($db, (int) $record['id'], 'admin.login');
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        // A session cookie set before commit may exist but will not be valid.
        dmo_admin_cookie('', time() - 3600);
        throw $error;
    }
    dmo_json(200, ['ok' => true, 'status' => 'signed_in']);
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin login');
    dmo_json(503, ['ok' => false, 'error' => 'admin_service_unavailable']);
}
