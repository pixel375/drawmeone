<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

dmo_reject_non_get();

try {
    // Return only a safe setup stage. Never expose names, passwords or SQL text.
    if (dmo_private_env_path() === null) {
        dmo_json(503, ['ok' => false, 'status' => 'private_env_not_found']);
    }
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $required) {
        if (dmo_config($required) === '') {
            dmo_json(503, ['ok' => false, 'status' => 'database_config_incomplete']);
        }
    }
    if (!extension_loaded('pdo_mysql')) {
        dmo_json(503, ['ok' => false, 'status' => 'pdo_mysql_unavailable']);
    }
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
} catch (PDOException $error) {
    dmo_log_problem($error, 'database health');
    // A missing schema table is distinct from a failed DB connection.
    if ($error->getCode() === '42S02') {
        dmo_json(503, ['ok' => false, 'status' => 'schema_setup_required']);
    }
    dmo_json(503, [
        'ok' => false,
        'service' => 'drawmeone-backend',
        'status' => 'database_connection_failed',
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'database health');
    dmo_json(503, [
        'ok' => false,
        'service' => 'drawmeone-backend',
        'status' => 'setup_required',
    ]);
}
