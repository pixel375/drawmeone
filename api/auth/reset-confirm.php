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
        $stmt = $db->prepare(
            'SELECT id, user_id FROM dmo_auth_tokens WHERE token_hash = :hash ' .
            "AND purpose = 'reset_password' AND consumed_at IS NULL " .
            'AND expires_at > UTC_TIMESTAMP() LIMIT 1 FOR UPDATE'
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
        $db->prepare(
            'UPDATE dmo_auth_tokens SET consumed_at = UTC_TIMESTAMP() WHERE id = :id'
        )->execute(['id' => $record['id']]);
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
    dmo_auth_cookie('', time() - 3600);
    dmo_json(200, ['ok' => true, 'status' => 'password_updated']);
} catch (Throwable $error) {
    dmo_log_problem($error, 'reset confirmation');
    dmo_json(503, ['ok' => false, 'error' => 'account_service_unavailable']);
}
