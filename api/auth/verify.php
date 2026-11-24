<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/auth.php';
dmo_auth_require_enabled();
$input = dmo_auth_json_request();
$token = dmo_auth_field($input, 'token', 128);
if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
    dmo_json(422, ['ok' => false, 'error' => 'invalid_or_expired_link']);
}
try {
    $db = dmo_database();
    if (!dmo_auth_rate_limit($db, 'verify-ip', '', 20, 60)) {
        dmo_json(429, ['ok' => false, 'error' => 'too_many_attempts']);
    }

    $db->beginTransaction();
    try {
        $stmt = $db->prepare(
            'SELECT t.id, t.user_id FROM dmo_auth_tokens t JOIN dmo_users u ' .
            'ON u.id = t.user_id WHERE t.token_hash = :hash ' .
            "AND t.purpose = 'verify_email' AND t.consumed_at IS NULL " .
            "AND t.expires_at > UTC_TIMESTAMP() AND u.account_status = 'pending_verification' " .
            "AND u.role = 'customer' AND u.email_verified_at IS NULL " .
            'LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['hash' => hash('sha256', $token)]);
        $record = $stmt->fetch();
        if (!is_array($record)) {
            $db->rollBack();
            dmo_json(422, ['ok' => false, 'error' => 'invalid_or_expired_link']);
        }
        $db->prepare(
            "UPDATE dmo_users SET account_status = 'active', " .
            "email_verified_at = UTC_TIMESTAMP() WHERE id = :id"
        )->execute(['id' => $record['user_id']]);
        // All pending verification links for the now-active account are
        // obsolete, including previously issued resend links.
        $db->prepare(
            "UPDATE dmo_auth_tokens SET consumed_at = UTC_TIMESTAMP() " .
            "WHERE user_id = :user AND purpose = 'verify_email' AND consumed_at IS NULL"
        )->execute(['user' => $record['user_id']]);
        $db->prepare(
            "INSERT INTO dmo_audit_log (actor_id, action, entity_type, entity_id) " .
            "VALUES (:id, 'account.email_verified', 'user', :entity_id)"
        )->execute(['id' => $record['user_id'], 'entity_id' => $record['user_id']]);
        $db->commit();
    } catch (Throwable $error) {
        $db->rollBack();
        throw $error;
    }
    dmo_json(200, ['ok' => true, 'status' => 'email_verified']);
} catch (Throwable $error) {
    dmo_log_problem($error, 'email verification');
    dmo_json(503, ['ok' => false, 'error' => 'account_service_unavailable']);
}
