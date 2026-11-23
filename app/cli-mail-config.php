<?php
declare(strict_types=1);
require_once __DIR__ . '/mailer.php';

// This script is ONLY called locally by the SSH-authenticated GitHub Actions
// maintenance runner. Input is a bounded JSON object read on stdin.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (($argv[1] ?? '') !== 'configure') {
    fwrite(STDERR, "Usage: php app/cli-mail-config.php configure < JSON_ON_STDIN\n");
    exit(2);
}
try {
    if (dmo_config('DMO_AUTH_ENABLED') === '1' ||
        dmo_config('DMO_REGISTRATION_ENABLED') === '1' ||
        dmo_config('DMO_REQUESTS_ENABLED') === '1') {
        throw new RuntimeException('Customer-facing features must be disabled for this setup.');
    }
    $raw = stream_get_contents(STDIN, 8193);
    if (!is_string($raw) || strlen($raw) > 8192) {
        throw new RuntimeException('Invalid mail configuration input size.');
    }
    $values = json_decode($raw, true, 5, JSON_THROW_ON_ERROR);
    if (!is_array($values) || array_is_list($values) ||
        array_diff(array_keys($values), ['host','port','user','password']) !== [] ||
        count($values) !== 4) {
        throw new RuntimeException('Invalid mail configuration fields.');
    }
    foreach (['host','port','user','password'] as $key) {
        if (!is_string($values[$key] ?? null)) {
            throw new RuntimeException('Missing SMTP configuration field.');
        }
    }
    $host = strtolower(trim($values['host']));
    $port = $values['port'];
    $user = strtolower(trim($values['user']));
    $password = $values['password'];
    if (!preg_match('/^[a-z0-9][a-z0-9.-]{0,252}$/D', $host) ||
        !str_contains($host, '.') || str_contains($host, '..') ||
        !in_array($port, ['465','587'], true) ||
        filter_var($user, FILTER_VALIDATE_EMAIL) === false ||
        !str_ends_with($user, '@drawmeone.com') ||
        preg_match('/[\r\n\x00-\x1f\x7f]/', $user) ||
        strlen($password) < 8 || strlen($password) > 255 ||
        preg_match('/[\r\n\x00-\x1f\x7f]/', $password)) {
        throw new RuntimeException('Invalid SMTP configuration values.');
    }
    if (!extension_loaded('curl')) {
        throw new RuntimeException('PHP cURL unavailable.');
    }
    $env = dmo_private_env_path();
    if ($env === null || is_link($env) || !is_writable($env) ||
        dirname($env) === realpath(dirname(__DIR__))) {
        throw new RuntimeException('Private .env not safe or writable.');
    }
    $old = file_get_contents($env);
    if (!is_string($old) || strlen($old) > 131072) {
        throw new RuntimeException('Private .env unavailable.');
    }
    $remove = [
        'DMO_MAIL_TRANSPORT' => true,
        'DMO_MAIL_FROM' => true,
        'DMO_MAIL_ENABLED' => true,
        'DMO_SMTP_HOST' => true,
        'DMO_SMTP_PORT' => true,
        'DMO_SMTP_USER' => true,
        'DMO_SMTP_PASSWORD' => true,
        'DMO_SMTP_PASSWORD_B64' => true,
        'DMO_ALLOW_PHP_MAIL' => true,
    ];
    $retained = [];
    foreach (preg_split('/\r\n|\r|\n/', $old) as $line) {
        if (preg_match('/^[ \t]*([A-Z][A-Z0-9_]*)[ \t]*=/', $line, $m) &&
            isset($remove[$m[1]])) {
            continue;
        }
        $retained[] = $line;
    }
    $new = rtrim(implode("\n", $retained), "\n") . "\n" .
        "# DrawMeOne verified TLS SMTP transport (GitHub maintenance)\n" .
        "DMO_MAIL_TRANSPORT=smtp\n" .
        "DMO_MAIL_FROM=" . $user . "\n" .
        "DMO_MAIL_ENABLED=1\n" .
        "DMO_SMTP_HOST=" . $host . "\n" .
        "DMO_SMTP_PORT=" . $port . "\n" .
        "DMO_SMTP_USER=" . $user . "\n" .
        "DMO_SMTP_PASSWORD_B64=" . base64_encode($password) . "\n";
    $tmp = tempnam(dirname($env), '.dmo-mail-');
    if ($tmp === false) {
        throw new RuntimeException('Unable to create private mail config staging.');
    }
    try {
        if (file_put_contents($tmp, $new, LOCK_EX) !== strlen($new) ||
            !chmod($tmp, 0600) || !rename($tmp, $env)) {
            throw new RuntimeException('Unable to apply private mail settings.');
        }
    } finally {
        if (is_file($tmp)) @unlink($tmp);
    }
    echo "smtp_transport_configured=yes\n";
    echo "customer_activation_unchanged=yes\n";
} catch (Throwable $error) {
    // Never print raw exception messages or configuration values: the SMTP
    // password is private even in GitHub Actions logs.
    fwrite(STDERR, "SMTP configuration could not be completed (" .
        get_class($error) . ").\n");
    exit(1);
}
