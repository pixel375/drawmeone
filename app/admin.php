<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// Admin access requires a strong password, rate limits, a verified admin
// role and a short-lived secure session. External CAPTCHA is an optional
// server-side policy for this private login, not a client-selectable bypass.
const DMO_ADMIN_COOKIE = '__Host-dmo_admin';
const DMO_ADMIN_TTL_SECONDS = 12 * 3600;
const DMO_ADMIN_IDLE_MINUTES = 30;

function dmo_admin_captcha_required(): bool
{
    // Default password-only for the private artist account. Unknown values
    // fail closed; enable CAPTCHA explicitly if the owner restores it.
    return dmo_config('DMO_ADMIN_REQUIRE_CAPTCHA', '0') !== '0';
}

function dmo_admin_enabled(): bool
{
    return dmo_config('DMO_ADMIN_ENABLED') === '1'
        && strlen(dmo_config('DMO_APP_KEY')) >= 32
        && (!dmo_admin_captcha_required() || (
            dmo_config('DMO_TURNSTILE_SITE_KEY') !== ''
            && dmo_config('DMO_TURNSTILE_SECRET') !== ''
            && extension_loaded('curl')
        ));
}

function dmo_admin_schema_ready(PDO $db): bool
{
    $query = $db->prepare(
        'SELECT version FROM dmo_schema_migrations WHERE version = :v LIMIT 1'
    );
    $query->execute(['v' => '002_auth_rate_limits']);
    return $query->fetchColumn() === '002_auth_rate_limits';
}

function dmo_admin_require_ready(): void
{
    if (!dmo_admin_enabled()) {
        dmo_json(503, ['ok' => false, 'error' => 'admin_service_unavailable']);
    }
    try {
        if (!dmo_admin_schema_ready(dmo_database())) {
            dmo_json(503, ['ok' => false, 'error' => 'admin_service_unavailable']);
        }
    } catch (Throwable $error) {
        dmo_log_problem($error, 'admin readiness');
        dmo_json(503, ['ok' => false, 'error' => 'admin_service_unavailable']);
    }
}

function dmo_admin_cookie(string $token, int $expires): void
{
    setcookie(DMO_ADMIN_COOKIE, $token, [
        'expires' => $expires,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function dmo_admin_issue_session(PDO $db, int $adminId, bool $setCookie=true): string
{
    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare(
        'INSERT INTO dmo_sessions (user_id, session_hash, expires_at, last_seen_at) ' .
        'VALUES (:uid, :hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 12 HOUR), UTC_TIMESTAMP())'
    );
    $stmt->execute(['uid' => $adminId, 'hash' => hash('sha256', $token)]);
    if($setCookie)dmo_admin_cookie($token, time() + DMO_ADMIN_TTL_SECONDS);
    return $token;
}

function dmo_admin_current_session(PDO $db): ?array
{
    if(!dmo_auth_https())return null;
    $token = $_COOKIE[DMO_ADMIN_COOKIE] ?? null;
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT s.id AS session_id, s.session_hash, u.id AS user_id, ' .
        'u.email, u.display_name FROM dmo_sessions s JOIN dmo_users u ' .
        'ON s.user_id = u.id WHERE s.session_hash = :hash ' .
        'AND s.expires_at > UTC_TIMESTAMP() ' .
        'AND s.last_seen_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 MINUTE) ' .
        "AND u.role = 'admin' AND u.account_status = 'active' " .
        'AND u.email_verified_at IS NOT NULL LIMIT 1'
    );
    $stmt->execute(['hash' => hash('sha256', $token)]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }
    // Idle timer refreshes only after the role and expiry checks pass.
    $db->prepare(
        'UPDATE dmo_sessions SET last_seen_at = UTC_TIMESTAMP() WHERE id = :sid'
    )->execute(['sid' => $row['session_id']]);
    return $row;
}

function dmo_admin_require_session(PDO $db): array
{
    dmo_admin_require_ready();
    $session = dmo_admin_current_session($db);
    if ($session === null) {
        dmo_json(401, ['ok' => false, 'error' => 'admin_login_required']);
    }
    return $session;
}

function dmo_admin_audit(PDO $db, int $id, string $action): void
{
    $stmt = $db->prepare(
        'INSERT INTO dmo_audit_log (actor_id, action, entity_type, entity_id) ' .
        "VALUES (:uid, :action, 'user', :entity)"
    );
    $stmt->execute(['uid' => $id, 'action' => $action, 'entity' => $id]);
}

/** Serialize credential checks with CLI reset and self-service security changes. */
function dmo_admin_login_lock(PDO $db,int $id,string $verifiedHash): bool
{
    if(!$db->inTransaction())throw new LogicException('Account transaction required');
    $q=$db->prepare("SELECT password_hash,role,account_status,email_verified_at FROM dmo_users WHERE id=:id FOR UPDATE");
    $q->execute(['id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    return is_array($row)&&$row['role']==='admin'&&$row['account_status']==='active'&&$row['email_verified_at']!==null&&hash_equals($verifiedHash,$row['password_hash']);
}
function dmo_admin_security_summary(PDO $db,int $user): array
{
    $q=$db->prepare("SELECT COUNT(*) FROM dmo_sessions WHERE user_id=:id AND expires_at>UTC_TIMESTAMP() AND last_seen_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)");
    $q->execute(['id'=>$user]);$count=(int)$q->fetchColumn();
    $q=$db->prepare("SELECT action,created_at FROM dmo_audit_log WHERE entity_type='user' AND entity_id=:id AND actor_id=:actor AND action IN ('admin.bootstrap','admin.login','admin.logout','admin.password_reset_cli','admin.password_changed','admin.other_sessions_revoked') ORDER BY id DESC LIMIT 10");
    $q->execute(['id'=>$user,'actor'=>$user]);
    return ['active_sessions'=>$count,'activity'=>$q->fetchAll(PDO::FETCH_ASSOC)];
}
