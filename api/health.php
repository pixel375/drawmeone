<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

dmo_reject_non_get();

try {
    $database = dmo_database();
    $query = $database->prepare(
        'SELECT version FROM dmo_schema_migrations WHERE version = :version LIMIT 1'
    );
    $query->execute(['version' => '001_initial_schema']);
    $schemaOk = $query->fetchColumn() === '001_initial_schema';

    if (!$schemaOk) {
        dmo_json(503, [
            'ok' => false,
            'status' => 'schema_setup_required',
        ]);
    }

    dmo_json(200, [
        'ok' => true,
        'service' => 'drawmeone-backend',
        'status' => 'database_connected',
        'api_version' => '1',
        'writes_enabled' => false,
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'database health');
    dmo_json(503, [
        'ok' => false,
        'service' => 'drawmeone-backend',
        'status' => 'setup_required',
    ]);
}
