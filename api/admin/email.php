<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/admin.php';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    dmo_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

try {
    $db = dmo_database();
    $admin = dmo_admin_require_session($db);
    if ($method === 'GET') {
        dmo_json(200, [
            'ok' => true,
            'mail_ready' => dmo_mail_ready(),
            'transport' => in_array(dmo_mail_transport(), ['smtp','php'], true) ?
                dmo_mail_transport() : 'not_configured',
            'smtp_settings_present' => dmo_mail_smtp_ready(),
            'customer_login_enabled' => dmo_auth_configured(),
            'customer_registration_enabled' => dmo_auth_configured() &&
                dmo_config('DMO_REGISTRATION_ENABLED') === '1',
            'test_recipient' => 'registered administrator email',
            'test_limit' => '2 per hour',
        ]);
    }

    $input = dmo_auth_json_request();
    dmo_auth_verify_csrf($admin);
    if (array_keys($input) !== ['action'] || $input['action'] !== 'send_test') {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_action']);
    }
    if (!dmo_mail_ready()) {
        dmo_json(503, ['ok' => false, 'error' => 'mail_not_configured']);
    }
    if (!dmo_auth_rate_limit(
        $db, 'admin-mail-test', (string) $admin['user_id'], 2, 60
    )) {
        dmo_json(429, ['ok' => false, 'error' => 'test_limit_reached']);
    }

    // A fixed recipient: the email stored on the authenticated admin record.
    // Do not implement a user-supplied 'to' field or an unauthenticated relay.
    $recipient = (string) $admin['email'];
    $testCode = strtoupper(bin2hex(random_bytes(5)));
    $body = "This is a transaction-mail test for Draw Me One.\n\n" .
        "Test reference: " . $testCode . "\n" .
        "Generated: " . gmdate('Y-m-d H:i:s') . " UTC\n\n" .
        "If this message arrived, your mail server accepted and delivered " .
        "a message to this address. This does not guarantee delivery to " .
        "all email providers. No customer registration or commission " .
        "feature was enabled by this test.\n";
    $accepted = dmo_auth_send_email(
        $recipient, 'Draw Me One - mail delivery check', $body
    );
    if (!$accepted) {
        dmo_json(502, ['ok' => false, 'error' => 'mail_transport_failed']);
    }
    dmo_json(202, [
        'ok' => true,
        'transport_accepted' => true,
        'test_reference' => $testCode,
        'message' => 'Transport accepted the test. Confirm it arrived in Inbox or Spam.',
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin mail diagnostic');
    dmo_json(503, ['ok' => false, 'error' => 'admin_mail_diagnostic_unavailable']);
}
