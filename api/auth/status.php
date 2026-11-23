<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/requests.php';
dmo_reject_non_get();

$storageReady = false;
try {
    $stmt = dmo_database()->prepare(
        'SELECT version FROM dmo_schema_migrations WHERE version = :version'
    );
    $stmt->execute(['version' => '002_auth_rate_limits']);
    $storageReady = $stmt->fetchColumn() === '002_auth_rate_limits';
} catch (Throwable $error) {
    dmo_log_problem($error, 'auth schema status');
}
$ready = $storageReady && dmo_auth_configured();
$registration = $ready && dmo_config('DMO_REGISTRATION_ENABLED') === '1';

dmo_json(200, [
    'ok' => true,
    'auth_migration_ready' => $storageReady,
    'auth_available' => $ready,
    'registration_available' => $registration,
    'turnstile_site_key' => $ready ? dmo_config('DMO_TURNSTILE_SITE_KEY') : null,
    'email_verification_required' => true,
    'commissions_open' => dmo_requests_open(),
]);
