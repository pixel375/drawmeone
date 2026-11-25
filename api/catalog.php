<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/catalog.php';
dmo_reject_non_get();
try{
    $db=dmo_database();
    dmo_json(200,['ok'=>true,'requests_open'=>dmo_intake_public_state($db)['requests_open'],
        'currency_pricing_approved'=>dmo_catalog_approved()]+dmo_catalog_public($db));
}catch(Throwable $error){
    dmo_log_problem($error,'catalog');
    dmo_json(503,['ok'=>false,'error'=>'service_unavailable','requests_open'=>false]);
}
