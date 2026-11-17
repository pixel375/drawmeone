<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/auth.php';
dmo_auth_require_enabled();
$input = dmo_auth_json_request();
$email = dmo_auth_email(dmo_auth_field($input, 'email', 254));
$captcha = dmo_auth_field($input, 'turnstile_token', 2048);

try {
    $db = dmo_database();
    if (!dmo_auth_rate_limit($db, 'reset-ip', '', 8, 60) ||
        !dmo_auth_rate_limit($db, 'reset-email', $email, 3, 60)) {
        dmo_json(429, ['ok' => false, 'error' => 'too_many_attempts']);
    }
    if (!dmo_auth_verify_turnstile($captcha, 'reset')) {
        dmo_json(422, ['ok' => false, 'error' => 'verification_failed']);
    }

    $stmt = $db->prepare(
        "SELECT id FROM dmo_users WHERE email = :email " .
        "AND role = 'customer' AND account_status = 'active' " .
        "AND email_verified_at IS NOT NULL LIMIT 1"
    );
    $stmt->execute(['email' => $email]);
    $id = $stmt->fetchColumn();

    if ($id !== false) {
        $db->beginTransaction();
        try {
            $db->prepare(
                "UPDATE dmo_auth_tokens SET consumed_at = UTC_TIMESTAMP() " .
                "WHERE user_id = :id AND purpose = 'reset_password' " .
                "AND consumed_at IS NULL"
            )->execute(['id' => $id]);
            $token = bin2hex(random_bytes(32));
            $db->prepare(
                "INSERT INTO dmo_auth_tokens (user_id, purpose, token_hash, expires_at) " .
                "VALUES (:id, 'reset_password', :hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))"
            )->execute(['id' => $id, 'hash' => hash('sha256', $token)]);
            $link = 'https://drawmeone.com/password-reset.html#reset=' . rawurlencode($token);
            $body = "Someone requested a password reset for your Draw Me One account.\n\n" .
                "To set a new password, use this one-time link:\n" . $link . "\n\n" .
                "The link expires in 30 minutes. If this wasn't you, ignore this email.\n";
            if (!dmo_auth_send_email($email, 'Reset your Draw Me One password', $body)) {
                throw new RuntimeException('Password reset message failed to queue.');
            }
            $db->commit();
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }
    }

    // Uniform response prevents leaking which email addresses are registered.
    dmo_json(202, [
        'ok' => true,
        'message' => 'If an eligible account exists, a password reset email will be sent.',
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'reset request');
    dmo_json(503, ['ok' => false, 'error' => 'account_service_unavailable']);
}
