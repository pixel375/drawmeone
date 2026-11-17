<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/admin.php';
dmo_reject_non_get();

$ready = false;
try {
    $ready = dmo_admin_schema_ready(dmo_database());
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin status readiness');
}
$enabled = $ready && dmo_admin_enabled();
dmo_json(200, [
    'ok' => true,
    'admin_schema_ready' => $ready,
    'admin_available' => $enabled,
    'turnstile_site_key' => $enabled ? dmo_config('DMO_TURNSTILE_SITE_KEY') : null,
    'mfa_required' => false,
    'commission_writes_enabled' => false,
]);
