<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/auth.php';
dmo_auth_require_enabled(true);
$input = dmo_auth_json_request();

$name = dmo_auth_field($input, 'display_name', 120);
$email = dmo_auth_email(dmo_auth_field($input, 'email', 254));
$password = $input['password'] ?? '';
$captcha = dmo_auth_field($input, 'turnstile_token', 2048);

if (strlen($name) < 2 || strlen($name) > 120 ||
    preg_match('/[\x00-\x1f\x7f]/', $name) ||
    !is_string($password) || !dmo_auth_password($password)) {
    dmo_json(422, ['ok' => false, 'error' => 'invalid_registration_details']);
}

try {
    $db = dmo_database();
    if (!dmo_auth_rate_limit($db, 'signup-ip', '', 5, 60) ||
        !dmo_auth_rate_limit($db, 'signup-email', $email, 3, 60)) {
        dmo_json(429, ['ok' => false, 'error' => 'too_many_attempts']);
    }
    if (!dmo_auth_verify_turnstile($captcha, 'signup')) {
        dmo_json(422, ['ok' => false, 'error' => 'verification_failed']);
    }

    $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $hash = password_hash($password, $algorithm);
    if (!is_string($hash)) {
        throw new RuntimeException('Password hashing unavailable.');
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            "INSERT INTO dmo_users (display_name, email, password_hash, role, account_status) " .
            "VALUES (:name, :email, :password_hash, 'customer', 'pending_verification')"
        );
        $stmt->execute([
            'name' => $name, 'email' => $email, 'password_hash' => $hash,
        ]);
        $userId = (int) $db->lastInsertId();
        $token = dmo_auth_make_verification($db, $userId);
        $link = 'https://drawmeone.com/account.html#verify=' . rawurlencode($token);
        $body = "Welcome to Draw Me One!\n\n" .
            "Please confirm your email address using this one-time link:\n" .
            $link . "\n\n" .
            "This link expires in 24 hours. If you didn't create an account, ignore this message.\n";
        if (!dmo_auth_send_email($email, 'Confirm your Draw Me One account', $body)) {
            throw new RuntimeException('Verification message was not accepted by mail transport.');
        }
        $audit = $db->prepare(
            "INSERT INTO dmo_audit_log (actor_id, action, entity_type, entity_id) " .
            "VALUES (:id, 'account.created', 'user', :entity_id)"
        );
        $audit->execute(['id' => $userId, 'entity_id' => $userId]);
        $db->commit();
    } catch (PDOException $error) {
        $db->rollBack();
        if ($error->getCode() !== '23000') {
            throw $error;
        }
        // An existing address receives the same public response as a new one.
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }

    dmo_json(202, [
        'ok' => true,
        'message' => 'If the address is eligible, an email verification link will be sent.',
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'signup');
    dmo_json(503, ['ok' => false, 'error' => 'account_service_unavailable']);
}
