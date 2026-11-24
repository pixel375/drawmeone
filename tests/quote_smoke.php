<?php
declare(strict_types=1);

// Source-level regression with no database, network, sessions or mail.
if (PHP_SAPI !== 'cli') exit(2);
$envFile = tempnam(sys_get_temp_dir(), 'dmo-quote-smoke-');
if (!is_string($envFile)) exit(1);
file_put_contents($envFile,
    "DMO_REQUEST_POLICY_VERSION=2026-11-reviewed\n" .
    "DMO_AUTH_ENABLED=0\nDMO_QUOTE_DELIVERY_ENABLED=0\n" .
    "DMO_QUOTE_RESPONSES_ENABLED=0\n");
putenv('DMO_ENV_FILE=' . $envFile);
try {
    require_once dirname(__DIR__) . '/app/quotes.php';
    $offer = [
        'status' => 'sent',
        'sent_at' => '2026-11-23 22:00:00',
        'validity_ends_at' => '2026-11-30 22:00:00',
        'is_expired' => 0,
    ];
    $checks = [
        dmo_quote_can_respond($offer, true, '2026-11-reviewed'),
        !dmo_quote_can_respond($offer, false, '2026-11-reviewed'),
        !dmo_quote_can_respond($offer, true, '2025-old'),
        !dmo_quote_can_respond(array_merge($offer, ['status'=>'draft']),
            true, '2026-11-reviewed'),
        !dmo_quote_can_respond(array_merge($offer, ['status'=>'superseded']),
            true, '2026-11-reviewed'),
        !dmo_quote_can_respond(array_merge($offer, ['status'=>'accepted']),
            true, '2026-11-reviewed'),
        !dmo_quote_can_respond(array_merge($offer, ['is_expired'=>1]),
            true, '2026-11-reviewed'),
        !dmo_quote_can_respond(array_merge($offer, ['sent_at'=>null]),
            true, '2026-11-reviewed'),
        !dmo_quote_can_respond(array_merge($offer, ['validity_ends_at'=>null]),
            true, '2026-11-reviewed'),
        !dmo_quote_delivery_enabled(),
        !dmo_quote_response_enabled(),
    ];
    if (in_array(false, $checks, true)) {
        throw new RuntimeException('Quote security smoke assertion failed.');
    }
    echo "QUOTE_SECURITY_SMOKE=PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, "QUOTE_SECURITY_SMOKE=FAIL\n");
    exit(1);
} finally {
    @unlink($envFile);
}
