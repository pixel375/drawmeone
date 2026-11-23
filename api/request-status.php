<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/requests.php';
dmo_reject_non_get();

dmo_json(200, [
    'ok' => true,
    'requests_open' => dmo_requests_open(),
    'requires_account' => true,
    'text_only' => true,
    'uploads_available' => false,
    'quotes_sent_automatically' => false,
    'policy_version' => dmo_requests_open() ? dmo_requests_policy_version() : null,
]);
