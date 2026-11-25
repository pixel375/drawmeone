<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/requests.php';
dmo_reject_non_get();

$intake=dmo_intake_public_state();
$policy=dmo_policy_current();
dmo_json(200, [
    'ok' => true,
    'requests_open' => $intake['requests_open'],
    'intake_reason' => $intake['intake_reason'],
    'requires_account' => true,
    'text_only' => true,
    'uploads_available' => false,
    'quotes_sent_automatically' => false,
    'policy_version' => $intake['requests_open'] ? $policy['version'] : null,
    'policy_sha256' => $intake['requests_open'] ? $policy['sha256'] : null,
]);
