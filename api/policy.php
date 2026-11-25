<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/policies.php';
dmo_reject_non_get();
$doc=dmo_policy_public_reference($_GET['version']??null,$_GET['sha256']??null);
if($doc===null)dmo_json(404,['ok'=>false,'error'=>'policy_not_available']);
dmo_json(200,['ok'=>true,'policy'=>$doc]);
