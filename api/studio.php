<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/studio-v2.php';
dmo_reject_non_get();
try{dmo_json(200,['ok'=>true,'settings'=>dmo_studio_load(dmo_database())['settings']]);}
catch(Throwable $e){dmo_log_problem($e,'studio public preferences');dmo_json(503,['ok'=>false,'error'=>'studio_unavailable']);}
