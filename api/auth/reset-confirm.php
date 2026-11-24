<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/auth.php';
dmo_auth_require_enabled();
$input = dmo_auth_json_request();
$token = dmo_auth_field($input, 'token', 128);
$password = $input['password'] ?? '';
if (!preg_match('/^[a-f0-9]{64}$/D', $token) ||
    !is_string($password) || !dmo_auth_password($password)) {
    dmo_json(422, ['ok' => false, 'error' => 'invalid_input']);
}
try {
    $db = dmo_database();
    if (!dmo_auth_rate_limit($db, 'reset-confirm-ip', '', 16, 60)) {
        dmo_json(429, ['ok' => false, 'error' => 'too_many_attempts']);
    }
    $db->beginTransaction();
    try {
        // Only active, email-verified CUSTOMER accounts can use public
        // recovery links, even if a token for another role somehow exists.
        $stmt = $db->prepare(
            'SELECT t.id, t.user_id, u.email FROM dmo_auth_tokens t ' .
            'JOIN dmo_users u ON u.id = t.user_id WHERE t.token_hash = :hash ' .
            "AND t.purpose = 'reset_password' AND t.consumed_at IS NULL " .
            "AND t.expires_at > UTC_TIMESTAMP() AND u.role = 'customer' " .
            "AND u.account_status = 'active' AND u.email_verified_at IS NOT NULL " .
            'LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $record = $stmt->fetch();
        if (!is_array($record)) {
            $db->rollBack();
            dmo_json(422, ['ok' => false, 'error' => 'invalid_or_expired_link']);
        }
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);
        if (!is_string($hash)) {
            throw new RuntimeException('Unable to hash password.');
        }
        $update = $db->prepare(
            "UPDATE dmo_users SET password_hash = :hash WHERE id = :id " .
            "AND account_status = 'active'"
        );
        $update->execute(['hash' => $hash, 'id' => $record['user_id']]);
        if ($update->rowCount() !== 1) {
            $db->rollBack();
            dmo_json(422, ['ok' => false, 'error' => 'invalid_or_expired_link']);
        }
        // Invalidate every outstanding reset link for the account, not only
        // the presented token. This also closes a concurrent reset-request
        // race that could otherwise leave a second usable recovery link.
        $db->prepare(
            "UPDATE dmo_auth_tokens SET consumed_at = UTC_TIMESTAMP() " .
            "WHERE user_id = :id AND purpose = 'reset_password' AND consumed_at IS NULL"
        )->execute(['id' => $record['user_id']]);
        // Logging out every existing device is mandatory after recovery.
        $db->prepare('DELETE FROM dmo_sessions WHERE user_id = :id')
            ->execute(['id' => $record['user_id']]);
        $db->prepare(
            "INSERT INTO dmo_audit_log (actor_id, action, entity_type, entity_id) " .
            "VALUES (:id, 'account.password_reset', 'user', :entity)"
        )->execute(['id' => $record['user_id'], 'entity' => $record['user_id']]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    // This notification is intentionally sent AFTER commit. A mail outage
    // must never roll back a completed reset or suggest the old password works.
    try {
        $message = "The password for your Draw Me One account was changed.\n\n" .
            "If you made this change, no action is required. If you did not, " .
            "contact support@drawmeone.com promptly. Existing sessions " .
            "have been signed out.\n";
        if (!dmo_auth_send_email((string) $record['email'],
            'Draw Me One account password changed', $message)) {
            error_log('[DrawMeOne] Password-change notification not accepted.');
        }
    } catch (Throwable $notificationError) {
        error_log('[DrawMeOne] Password-change notification unavailable.');
    }
    dmo_auth_cookie('', time() - 3600);
    dmo_json(200, ['ok' => true, 'status' => 'password_updated']);
} catch (Throwable $error) {
    dmo_log_problem($error, 'reset confirmation');
    dmo_json(503, ['ok' => false, 'error' => 'account_service_unavailable']);
}
