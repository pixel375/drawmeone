<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/design.php';
dmo_reject_non_get();

try {
    $settings = dmo_design_load(dmo_database(), 'published');
    // The strict allowlist excludes arbitrary or confidential fields.
    dmo_json(200, [
        'ok' => true,
        'published' => $settings['exists'],
        'version' => $settings['version'],
        'settings' => $settings['settings'],
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'published site settings');
    dmo_json(503, ['ok' => false, 'error' => 'service_unavailable']);
}
