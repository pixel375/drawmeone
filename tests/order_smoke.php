<?php
declare(strict_types=1);
// Non-network source smoke: no customer records, payments or DB writes.
if (PHP_SAPI !== 'cli') exit(2);
$env = tempnam(sys_get_temp_dir(), 'dmo-orders-');
if (!is_string($env)) exit(1);
file_put_contents($env,
    "DMO_AUTH_ENABLED=0\n" .
    "DMO_ORDER_ADMIN_ENABLED=1\nDMO_ORDER_TERMS_APPROVED=1\n" .
    "DMO_REQUEST_ADMIN_WRITES_ENABLED=1\n" .
    "DMO_REQUEST_POLICY_VERSION=2026-11-reviewed\n");
putenv('DMO_ENV_FILE=' . $env);
try {
    require_once dirname(__DIR__) . '/app/orders.php';
    $checks = [
        !dmo_orders_admin_enabled(),
        dmo_orders_status_label('awaiting_arrangement') ===
            'Awaiting payment arrangements',
        dmo_orders_status_label('in_progress') === 'Artwork in progress',
        dmo_orders_status_label('not_a_status') === 'Order under review',
        !dmo_quote_response_enabled(),
    ];
    if (in_array(false, $checks, true)) {
        throw new RuntimeException('Order gates or labels failed.');
    }
    echo "ORDER_SECURITY_SMOKE=PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, "ORDER_SECURITY_SMOKE=FAIL\n");
    exit(1);
} finally {
    @unlink($env);
}
