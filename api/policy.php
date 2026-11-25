<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/policies.php';
dmo_reject_non_get();
$version=$_GET['version']??null;$sha=$_GET['sha256']??null;
if($version===null&&$sha===null){
    $doc=dmo_policy_current();
}else{
    if(!is_string($version)||!is_string($sha)||!preg_match('/^[a-f0-9]{64}$/D',$sha))
        dmo_json(404,['ok'=>false,'error'=>'policy_not_available']);
    $doc=$version===dmo_config('DMO_REQUEST_POLICY_VERSION')?dmo_policy_current():dmo_policy_archived($version);
    if($doc===null||!hash_equals($doc['sha256'],$sha))$doc=null;
    // The current version must be pinned and explicitly published.
    if($version===dmo_config('DMO_REQUEST_POLICY_VERSION')&&dmo_policy_current()===null)$doc=null;
}
if($doc===null)dmo_json(404,['ok'=>false,'error'=>'policy_not_available']);
dmo_json(200,['ok'=>true,'policy'=>$doc]);
