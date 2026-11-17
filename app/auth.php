<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const DMO_SESSION_COOKIE = '__Host-dmo_session';
const DMO_AUTH_ORIGIN = 'https://drawmeone.com';

function dmo_auth_configured(): bool
{
    $from = dmo_config('DMO_MAIL_FROM');
    return dmo_config('DMO_AUTH_ENABLED') === '1'
        && strlen(dmo_config('DMO_APP_KEY')) >= 32
        && dmo_config('DMO_TURNSTILE_SITE_KEY') !== ''
        && dmo_config('DMO_TURNSTILE_SECRET') !== ''
        && dmo_config('DMO_MAIL_ENABLED') === '1'
        && filter_var($from, FILTER_VALIDATE_EMAIL) !== false
        && str_ends_with(strtolower($from), '@drawmeone.com');
}

function dmo_auth_require_enabled(bool $registration = false): void
{
    if (!dmo_auth_configured()
        || ($registration && dmo_config('DMO_REGISTRATION_ENABLED') !== '1')) {
        dmo_json(503, ['ok' => false, 'error' => 'account_service_unavailable']);
    }

    try {
        $stmt = dmo_database()->prepare(
            'SELECT version FROM dmo_schema_migrations WHERE version = :version LIMIT 1'
        );
        $stmt->execute(['version' => '002_auth_rate_limits']);
        if ($stmt->fetchColumn() !== '002_auth_rate_limits') {
            dmo_json(503, ['ok' => false, 'error' => 'account_service_unavailable']);
        }
    } catch (Throwable $error) {
        dmo_log_problem($error, 'auth schema readiness');
        dmo_json(503, ['ok' => false, 'error' => 'account_service_unavailable']);
    }
}

function dmo_auth_https(): bool
{
    return (($_SERVER['HTTPS'] ?? '') === 'on')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function dmo_auth_json_request(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        dmo_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }
    // Reject credentialed requests over plain HTTP or an unexpected origin.
    if (!dmo_auth_https() || ($_SERVER['HTTP_ORIGIN'] ?? '') !== DMO_AUTH_ORIGIN) {
        dmo_json(403, ['ok' => false, 'error' => 'forbidden']);
    }
    $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($type !== 'application/json') {
        dmo_json(415, ['ok' => false, 'error' => 'json_required']);
    }
    $max = 16384;
    $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declared > $max) {
        dmo_json(413, ['ok' => false, 'error' => 'request_too_large']);
    }
    $body = file_get_contents('php://input', false, null, 0, $max + 1);
    if (!is_string($body) || strlen($body) > $max) {
        dmo_json(413, ['ok' => false, 'error' => 'request_too_large']);
    }
    try {
        $parsed = json_decode($body, false, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        dmo_json(400, ['ok' => false, 'error' => 'invalid_json']);
    }
    if (!$parsed instanceof stdClass) {
        dmo_json(400, ['ok' => false, 'error' => 'invalid_json']);
    }
    return (array) $parsed;
}

function dmo_auth_field(array $input, string $key, int $max = 4096): string
{
    $value = $input[$key] ?? '';
    if (!is_string($value) || strlen($value) > $max || str_contains($value, "\0")) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_input']);
    }
    return trim($value);
}

function dmo_auth_email(string $email): string
{
    $email = strtolower(trim($email));
    if (strlen($email) < 4 || strlen($email) > 254 ||
        filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_email']);
    }
    return $email;
}

function dmo_auth_password(string $password): bool
{
    return strlen($password) >= 12 && strlen($password) <= 128
        && !preg_match('/[\x00-\x1F\x7F]/', $password);
}

function dmo_auth_rate_limit(PDO $db, string $action, string $subject, int $max, int $windowMinutes): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // No plaintext IP or email is ever saved in the rate-limiter table.
    $hash = hash_hmac('sha256', $action . '|' . $ip . '|' . $subject, dmo_config('DMO_APP_KEY'));
    $sql = 'INSERT INTO dmo_rate_limits (key_hash, hits, window_started_at, expires_at) ' .
        'VALUES (:key_hash, 1, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' .
        (int) $windowMinutes . ' MINUTE)) ' .
        'ON DUPLICATE KEY UPDATE ' .
        'hits = IF(expires_at <= UTC_TIMESTAMP(), 1, hits + 1), ' .
        'window_started_at = IF(expires_at <= UTC_TIMESTAMP(), UTC_TIMESTAMP(), window_started_at), ' .
        'expires_at = IF(expires_at <= UTC_TIMESTAMP(), ' .
        'DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . (int) $windowMinutes . ' MINUTE), expires_at)';
    $db->prepare($sql)->execute(['key_hash' => $hash]);
    $stmt = $db->prepare('SELECT hits FROM dmo_rate_limits WHERE key_hash = :hash');
    $stmt->execute(['hash' => $hash]);
    return (int) $stmt->fetchColumn() <= $max;
}

function dmo_auth_verify_turnstile(string $token, string $action): bool
{
    if (strlen($token) < 10 || strlen($token) > 2048 || !extension_loaded('curl')) {
        return false;
    }
    $handle = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    if ($handle === false) {
        return false;
    }
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'secret' => dmo_config('DMO_TURNSTILE_SECRET'),
            'response' => $token,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 9,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $response = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if (!is_string($response) || $status !== 200 || strlen($response) > 12000) {
        return false;
    }
    $result = json_decode($response, true);
    return is_array($result)
        && ($result['success'] ?? false) === true
        && ($result['hostname'] ?? '') === 'drawmeone.com'
        && ($result['action'] ?? '') === $action;
}

function dmo_auth_send_email(string $recipient, string $subject, string $content): bool
{
    $from = dmo_config('DMO_MAIL_FROM');
    if (dmo_config('DMO_MAIL_ENABLED') !== '1'
        || filter_var($from, FILTER_VALIDATE_EMAIL) === false
        || !str_ends_with(strtolower($from), '@drawmeone.com')
        || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false
        || preg_match('/[\r\n]/', $recipient . $from . $subject)) {
        return false;
    }
    $headers = [
        'From' => 'Draw Me One <' . $from . '>',
        'Reply-To' => $from,
        'Content-Type' => 'text/plain; charset=UTF-8',
        'MIME-Version' => '1.0',
    ];
    return mail($recipient, $subject, $content, $headers);
}

function dmo_auth_make_verification(PDO $db, int $userId): string
{
    $raw = bin2hex(random_bytes(32));
    $stmt = $db->prepare(
        'INSERT INTO dmo_auth_tokens (user_id, purpose, token_hash, expires_at) ' .
        "VALUES (:id, 'verify_email', :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))"
    );
    $stmt->execute([
        'id' => $userId,
        'token_hash' => hash('sha256', $raw),
    ]);
    return $raw;
}

function dmo_auth_cookie(string $token, int $until): void
{
    setcookie(DMO_SESSION_COOKIE, $token, [
        'expires' => $until,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function dmo_auth_issue_session(PDO $db, int $userId): void
{
    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare(
        'INSERT INTO dmo_sessions (user_id, session_hash, expires_at, last_seen_at) ' .
        'VALUES (:user_id, :session_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 7 DAY), UTC_TIMESTAMP())'
    );
    $stmt->execute([
        'user_id' => $userId,
        'session_hash' => hash('sha256', $token),
    ]);
    dmo_auth_cookie($token, time() + 7 * 86400);
}

function dmo_auth_current_session(PDO $db): ?array
{
    $token = $_COOKIE[DMO_SESSION_COOKIE] ?? null;
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
        return null;
    }
    $stmt = $db->prepare(
        'SELECT s.id AS session_id, s.session_hash, u.id AS user_id, ' .
        'u.display_name, u.email, u.role FROM dmo_sessions s ' .
        'JOIN dmo_users u ON u.id = s.user_id ' .
        'WHERE s.session_hash = :hash AND s.expires_at > UTC_TIMESTAMP() ' .
        "AND u.account_status = 'active' AND u.email_verified_at IS NOT NULL LIMIT 1"
    );
    $stmt->execute(['hash' => hash('sha256', $token)]);
    $session = $stmt->fetch();
    return is_array($session) ? $session : null;
}

function dmo_auth_csrf(string $sessionHash): string
{
    return hash_hmac('sha256', 'dmo-csrf|' . $sessionHash, dmo_config('DMO_APP_KEY'));
}

function dmo_auth_verify_csrf(array $session): void
{
    $presented = $_SERVER['HTTP_X_DMO_CSRF'] ?? '';
    if (!is_string($presented)
        || !hash_equals(dmo_auth_csrf($session['session_hash']), $presented)) {
        dmo_json(403, ['ok' => false, 'error' => 'csrf_failed']);
    }
}
