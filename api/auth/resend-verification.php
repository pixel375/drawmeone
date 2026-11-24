<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/auth.php';

// A pending customer can obtain another one-time verification link.
// Keep responses identical for nonexistent, already verified and pending users.
dmo_auth_require_enabled();
$input = dmo_auth_json_request();
$email = dmo_auth_email(dmo_auth_field($input, 'email', 254));
$captcha = dmo_auth_field($input, 'turnstile_token', 2048);

try {
    $db = dmo_database();
    if (!dmo_auth_rate_limit($db, 'verify-resend-ip', '', 8, 60) ||
        !dmo_auth_rate_limit($db, 'verify-resend-email', $email, 3, 60)) {
        dmo_json(429, ['ok' => false, 'error' => 'too_many_attempts']);
    }
    if (!dmo_auth_verify_turnstile($captcha, 'resend')) {
        dmo_json(422, ['ok' => false, 'error' => 'verification_failed']);
    }

    $stmt = $db->prepare(
        "SELECT id FROM dmo_users WHERE email = :email AND role = 'customer' " .
        "AND account_status = 'pending_verification' AND email_verified_at IS NULL LIMIT 1"
    );
    $stmt->execute(['email' => $email]);
    $userId = $stmt->fetchColumn();
    if ($userId !== false) {
        $db->beginTransaction();
        try {
            $db->prepare(
                "UPDATE dmo_auth_tokens SET consumed_at = UTC_TIMESTAMP() " .
                "WHERE user_id = :id AND purpose = 'verify_email' AND consumed_at IS NULL"
            )->execute(['id' => $userId]);
            $token = dmo_auth_make_verification($db, (int) $userId);
            $link = 'https://drawmeone.com/account.html#verify=' . rawurlencode($token);
            $body = "You requested a new verification link for Draw Me One.\n\n" .
                "Confirm your email with this one-time link:\n" . $link . "\n\n" .
                "This link expires in 24 hours. Earlier links no longer work. " .
                "If you didn't request this, ignore the message.\n";
            if (!dmo_auth_send_email($email, 'Confirm your Draw Me One account', $body)) {
                throw new RuntimeException('Verification resend transport failure.');
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
    dmo_json(202, [
        'ok' => true,
        'message' => 'If the address has a pending account, a new verification email will be sent.',
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'verification resend');
    dmo_json(503, ['ok' => false, 'error' => 'account_service_unavailable']);
}
