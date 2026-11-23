<?php
declare(strict_types=1);

/**
 * Draw Me One: first-admin bootstrap / emergency password reset.
 * PHP CLI ONLY. This file must never be routed via HTTP or GitHub Actions.
 *
 * Usage:
 *  read -srp "New admin password: " DMO_ADMIN_PASSWORD; echo
 *  export DMO_ADMIN_PASSWORD
 *  php app/cli-admin.php create artist@example.com "Artist Name"
 *  unset DMO_ADMIN_PASSWORD
 *
 * Check: php app/cli-admin.php check artist@example.com\n * Reset: php app/cli-admin.php reset artist@example.com
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/bootstrap.php';

$action = $argv[1] ?? '';
$email = strtolower(trim($argv[2] ?? ''));
$name = trim($argv[3] ?? '');
$password = getenv('DMO_ADMIN_PASSWORD');

function dmo_cli_fail(string $message): never
{
    fwrite(STDERR, "Draw Me One: " . $message . "\n");
    exit(1);
}
if (!in_array($action, ['create', 'reset', 'check'], true)
    || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    dmo_cli_fail('Usage: php app/cli-admin.php create|reset|check email@example.com [display-name]');
}
if (!is_string($password) || strlen($password) < 16 || strlen($password) > 128
    || preg_match('/[\x00-\x1f\x7f]/', $password)) {
    dmo_cli_fail('Set DMO_ADMIN_PASSWORD securely to a strong 16-128 character password.');
}
if ($action === 'create' && (strlen($name) < 2 || strlen($name) > 120)) {
    dmo_cli_fail('A 2-120 character display name is required for create.');
}

try {
    $db = dmo_database();
    $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $hash = password_hash($password, $algo);
    if (!is_string($hash)) {
        dmo_cli_fail('Password hashing unavailable.');
    }
    if ($action === 'check') {
        // On-server, read-only password check for a CLI-created admin.
        // No passwords, password hashes, account email, or tokens are printed.
        $stmt = $db->prepare(
            'SELECT password_hash, role, account_status, email_verified_at ' .
            'FROM dmo_users WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => $email]);
        $record = $stmt->fetch();
        $dummy = '$2y$12$GoZeZg6Kj6baWePuXK8DQek3wK81b.wKK62JysP1B6bAFMFp9s1H6';
        $valid = password_verify($password,
            is_array($record) ? (string) $record['password_hash'] : $dummy);
        if ($valid && is_array($record)
            && $record['role'] === 'admin'
            && $record['account_status'] === 'active'
            && $record['email_verified_at'] !== null) {
            echo "Admin credentials and account status: OK\n";
            exit(0);
        }
        dmo_cli_fail('Admin credentials or account status: NOT VERIFIED. Check the exact admin email/password or use the reset command.');
    }
    $db->beginTransaction();
    if ($action === 'create') {
        // Never auto-create another admin or infer admin rights from customer accounts.
        $existing = $db->query(
            "SELECT id FROM dmo_users WHERE role = 'admin' LIMIT 1 FOR UPDATE"
        )->fetchColumn();
        if ($existing !== false) {
            $db->rollBack();
            dmo_cli_fail('An administrator already exists; use reset only.');
        }
        $stmt = $db->prepare(
            "INSERT INTO dmo_users " .
            "(display_name, email, password_hash, role, account_status, email_verified_at) " .
            "VALUES (:name, :email, :hash, 'admin', 'active', UTC_TIMESTAMP())"
        );
        $stmt->execute(['name' => $name, 'email' => $email, 'hash' => $hash]);
        $id = (int) $db->lastInsertId();
        $label = 'admin.bootstrap';
    } else {
        $stmt = $db->prepare(
            "SELECT id FROM dmo_users WHERE email = :email AND role = 'admin' " .
            "AND account_status = 'active' LIMIT 1 FOR UPDATE"
        );
        $stmt->execute(['email' => $email]);
        $id = (int) $stmt->fetchColumn();
        if ($id < 1) {
            $db->rollBack();
            dmo_cli_fail('Active admin with that email was not found.');
        }
        $db->prepare('UPDATE dmo_users SET password_hash = :hash WHERE id = :id')
            ->execute(['hash' => $hash, 'id' => $id]);
        $db->prepare('DELETE FROM dmo_sessions WHERE user_id = :id')
            ->execute(['id' => $id]);
        $label = 'admin.password_reset_cli';
    }
    $audit = $db->prepare(
        'INSERT INTO dmo_audit_log (actor_id, action, entity_type, entity_id) ' .
        "VALUES (:id, :action, 'user', :entity)"
    );
    $audit->execute(['id' => $id, 'action' => $label, 'entity' => $id]);
    $db->commit();
    echo $action === 'create'
        ? "Admin account created. Login remains disabled until DMO_ADMIN_ENABLED=1.\n"
        : "Admin password updated and all admin sessions revoked.\n";
} catch (Throwable $error) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    dmo_log_problem($error, 'CLI admin bootstrap');
    dmo_cli_fail('Unable to finish. Check private PHP error log and database.');
}
