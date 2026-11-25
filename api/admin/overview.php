<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/launch-readiness.php';
dmo_reject_non_get();
try{
    $db=dmo_database();
    dmo_admin_require_session($db);
    dmo_json(200,dmo_launch_overview($db));
}catch(Throwable $error){
    dmo_log_problem($error,'admin overview');
    dmo_json(503,['ok'=>false,'error'=>'admin_service_unavailable']);
}
