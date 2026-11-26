<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/bootstrap.php';
dmo_reject_non_get();
// Public liveness only; detailed setup stages are available in private diagnostics.
try {
    $db=dmo_database();
    $q=$db->prepare('SELECT version FROM dmo_schema_migrations WHERE version=:version LIMIT 1');
    $q->execute(['version'=>'001_initial_schema']);
    if($q->fetchColumn()!=='001_initial_schema')throw new RuntimeException('Schema unavailable.');
    dmo_json(200,['ok'=>true,'service'=>'drawmeone-backend','status'=>'database_connected','api_version'=>'1']);
} catch(Throwable $error) {
    dmo_log_problem($error,'database health');
    dmo_json(503,['ok'=>false,'service'=>'drawmeone-backend','status'=>'service_unavailable']);
}
