<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

// Single-purpose owner-authorized SMTP test. Can ONLY run from CLI.
// No arbitrary message, email destination, or password arguments.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== 'send-admin-test' || count($argv) !== 2) {
    fwrite(STDERR, "Usage: php app/cli-smtp-probe.php send-admin-test\n");
    exit(2);
}
try {
    if (dmo_config('DMO_AUTH_ENABLED') === '1' ||
        dmo_config('DMO_REGISTRATION_ENABLED') === '1' ||
        dmo_config('DMO_REQUESTS_ENABLED') === '1') {
        throw new RuntimeException('Customer rollout flags must remain closed.');
    }
    if (!dmo_mail_ready() || dmo_mail_transport() !== 'smtp') {
        throw new RuntimeException('SMTP not configured.');
    }
    $db = dmo_database();
    $q = $db->query(
        "SELECT id, email FROM dmo_users WHERE role = 'admin' " .
        "AND account_status = 'active' AND email_verified_at IS NOT NULL LIMIT 2"
    );
    $users = $q->fetchAll(PDO::FETCH_ASSOC);
    if (count($users) !== 1) {
        throw new RuntimeException('Expected one verified admin.');
    }
    $admin = $users[0];
    if (!dmo_auth_rate_limit($db, 'smtp-cli-delivery-test',
        (string) $admin['id'], 1, 60)) {
        throw new RuntimeException('SMTP test rate limit reached.');
    }
    $code = strtoupper(bin2hex(random_bytes(5)));
    echo "smtp_test_attempted=yes\n";
    echo "smtp_test_reference=" . $code . "\n";
    $diagnostic = null;
    $sent = dmo_mail_send(
        (string) $admin['email'],
        'Draw Me One - SMTP diagnostic ' . $code,
        "This is one delivery diagnostic sent to your registered administrator account.\n" .
        "Test reference: " . $code . "\n" .
        "No customer account or commission feature has been opened.\n",
        $diagnostic
    );
    $curlCode = is_array($diagnostic) ? (int) ($diagnostic['curl_code'] ?? -1) : -1;
    $smtpCode = is_array($diagnostic) ? (int) ($diagnostic['smtp_code'] ?? 0) : 0;
    echo "smtp_curl_code=" . $curlCode . "\n";
    echo "smtp_server_reply_code=" . $smtpCode . "\n";
    echo "smtp_transport_accepted=" . ($sent ? "yes" : "no") . "\n";
    echo "delivery_confirmation=not_available_from_smtp\n";
    exit($sent ? 0 : 1);
} catch (Throwable $error) {
    // Never reveal recipient email, SMTP credentials, PHP error descriptions,
    // or message contents in public GitHub Actions logs.
    fwrite(STDERR, "smtp_test_preflight_failed=" .
        get_class($error) . "\n");
    exit(1);
}
