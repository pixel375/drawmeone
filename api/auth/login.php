<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/auth.php';
dmo_auth_require_enabled();
$input = dmo_auth_json_request();
$email = dmo_auth_email(dmo_auth_field($input, 'email', 254));
$password = $input['password'] ?? '';
$captcha = dmo_auth_field($input, 'turnstile_token', 2048);
if (!is_string($password) || strlen($password) > 128 ||
    !is_string($captcha)) {
    dmo_json(422, ['ok' => false, 'error' => 'invalid_input']);
}
try {
    $db = dmo_database();
    if (!dmo_auth_rate_limit($db, 'login-ip', '', 20, 15) ||
        !dmo_auth_rate_limit($db, 'login-email', $email, 6, 15)) {
        dmo_json(429, ['ok' => false, 'error' => 'too_many_attempts']);
    }
    if (!dmo_auth_verify_turnstile($captcha, 'login')) {
        dmo_json(422, ['ok' => false, 'error' => 'verification_failed']);
    }

    $stmt = $db->prepare(
        'SELECT id, password_hash, role, account_status, email_verified_at ' .
        'FROM dmo_users WHERE email = :email LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();
    // Constant valid bcrypt string reduces user-existence timing differences.
    $dummy = '$2y$12$GoZeZg6Kj6baWePuXK8DQek3wK81b.wKK62JysP1B6bAFMFp9s1H6';
    $valid = password_verify($password, is_array($user) ? $user['password_hash'] : $dummy);
    if (!$valid || !is_array($user) ||
        $user['role'] !== 'customer' ||
        $user['account_status'] !== 'active' ||
        $user['email_verified_at'] === null) {
        dmo_json(401, ['ok' => false, 'error' => 'invalid_credentials']);
    }

    $db->beginTransaction();
    $locked=$db->prepare("SELECT password_hash,role,account_status,email_verified_at FROM dmo_users WHERE id=:id FOR UPDATE");
    $locked->execute(['id'=>$user['id']]);$current=$locked->fetch(PDO::FETCH_ASSOC);
    if(!is_array($current)||$current['role']!=='customer'||$current['account_status']!=='active'||$current['email_verified_at']===null||
        !hash_equals($user['password_hash'],$current['password_hash'])){
        $db->rollBack();dmo_json(401,['ok'=>false,'error'=>'invalid_credentials']);
    }
    if (password_needs_rehash($user['password_hash'],
        defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
        $newHash = password_hash($password,
            defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT);
        if (is_string($newHash)) {
            $db->prepare('UPDATE dmo_users SET password_hash = :hash WHERE id = :id')
                ->execute(['hash' => $newHash, 'id' => $user['id']]);
        }
    }
    $sessionToken=dmo_auth_issue_session($db, (int) $user['id'],false);
    $db->prepare(
        "INSERT INTO dmo_audit_log (actor_id, action, entity_type, entity_id) " .
        "VALUES (:id, 'account.login', 'user', :entity_id)"
    )->execute(['id' => $user['id'], 'entity_id' => $user['id']]);
    $db->commit();
    dmo_auth_cookie($sessionToken,time()+7*86400);
    dmo_json(200, ['ok' => true, 'status' => 'signed_in']);
} catch (Throwable $error) {
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    dmo_log_problem($error, 'login');
    dmo_json(503, ['ok' => false, 'error' => 'account_service_unavailable']);
}
