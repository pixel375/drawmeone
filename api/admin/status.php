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
    // Presence indicators ONLY; no keys, credentials or values are exposed.
    'app_key_present' => strlen(dmo_config('DMO_APP_KEY')) >= 32,
    'turnstile_keys_present' =>
        dmo_config('DMO_TURNSTILE_SITE_KEY') !== '' &&
        dmo_config('DMO_TURNSTILE_SECRET') !== '',
    'admin_available' => $enabled,
    'captcha_required' => dmo_admin_captcha_required(),
    'turnstile_site_key' => $enabled && dmo_admin_captcha_required() ? dmo_config('DMO_TURNSTILE_SITE_KEY') : null,
    'mfa_required' => false,
    'commission_writes_enabled' => false,
]);
