<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

/** Only immutable, explicitly approved, versioned plain-text documents. */
function dmo_policy_load(string $version): ?array
{
    if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,39}$/D',$version))return null;
    $directory=__DIR__.'/policies';
    // Synthetic content can only be loaded in the isolated local CI runtime.
    if(in_array(PHP_SAPI,['cli','cli-server'],true)&&getenv('DMO_CI_INTEGRATION')==='1'&&
        getenv('DB_HOST')==='127.0.0.1'&&getenv('DB_NAME')==='drawmeone_ci'&&getenv('DB_USER')==='ci_user')
        $directory=dirname(__DIR__).'/tests/fixtures/policies';
    $file=$directory.'/'.$version.'.json';
    if(is_link($directory)||is_link($file)||!is_file($file)||filesize($file)>100000)return null;
    $raw=file_get_contents($file);
    if(!is_string($raw))return null;
    try{$doc=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(Throwable $e){return null;}
    if(!is_array($doc)||count($doc)!==5||($doc['version']??null)!==$version||($doc['approved']??null)!==true||
        !is_string($doc['effective_date']??null)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$doc['effective_date']))return null;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$doc['effective_date']);
    if(!$date||$date->format('Y-m-d')!==$doc['effective_date']||$doc['effective_date']>gmdate('Y-m-d'))return null;
    foreach(['terms','privacy'] as $field){
        if(!is_string($doc[$field]??null)||strlen(trim($doc[$field]))<20||strlen($doc[$field])>40000||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$doc[$field]))return null;
    }
    unset($doc['approved']);$doc['sha256']=hash('sha256',$raw);
    return $doc;
}
function dmo_policy_current(): ?array
{
    if(dmo_config('DMO_PRIVACY_PUBLISHED')!=='1'||dmo_config('DMO_REQUEST_TERMS_APPROVED')!=='1')return null;
    $doc=dmo_policy_load(dmo_config('DMO_REQUEST_POLICY_VERSION'));
    $pin=dmo_config('DMO_REQUEST_POLICY_SHA256');
    return $doc!==null&&preg_match('/^[a-f0-9]{64}$/D',$pin)&&hash_equals($pin,$doc['sha256'])?$doc:null;
}
function dmo_policy_receipt(PDO $db,int $requestId): ?string
{
    $q=$db->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.policy_sha256')) FROM dmo_audit_log
        WHERE entity_type='commission_request' AND entity_id=:id AND action='request.submitted' ORDER BY id ASC LIMIT 1");
    $q->execute(['id'=>$requestId]);$sha=$q->fetchColumn();
    return is_string($sha)&&preg_match('/^[a-f0-9]{64}$/D',$sha)?$sha:null;
}
