<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/studio-v2.php';
dmo_reject_non_get();
try{
    $db=dmo_database();$s=dmo_studio_load($db)['settings'];
    $s['accepting']=$s['accepting']&&dmo_guest_ready($db);
    dmo_json(200,['ok'=>true,'settings'=>$s,'turnstile_site_key'=>$s['accepting']?dmo_config('DMO_TURNSTILE_SITE_KEY'):null]);
}
catch(Throwable $e){dmo_log_problem($e,'studio public preferences');dmo_json(503,['ok'=>false,'error'=>'studio_unavailable']);}
