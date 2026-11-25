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
/** Missing legacy metadata is distinct from a malformed or mismatched receipt. */
function dmo_policy_receipt_record(PDO $db,int $requestId): array
{
    $q=$db->prepare("SELECT details_json FROM dmo_audit_log WHERE entity_type='commission_request'
        AND entity_id=:id AND action='request.submitted' ORDER BY id ASC LIMIT 1");
    $q->execute(['id'=>$requestId]);$raw=$q->fetchColumn();
    if($raw===false||$raw===null)return ['state'=>'legacy','version'=>null,'sha256'=>null];
    try{$details=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(Throwable $e){$details=null;}
    if(!is_array($details)||!is_string($details['policy_version']??null)||
        !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,39}$/D',$details['policy_version'])||
        !is_string($details['policy_sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$details['policy_sha256']))
        return ['state'=>'invalid','version'=>null,'sha256'=>null];
    return ['state'=>'recorded','version'=>$details['policy_version'],'sha256'=>$details['policy_sha256']];
}
function dmo_policy_receipt(PDO $db,int $requestId): ?string
{
    return dmo_policy_receipt_record($db,$requestId)['sha256'];
}

/** Owner-reviewed continuation pins. Not a substitute for the current bundle. */
function dmo_policy_archived(string $version): ?array
{
    $raw=dmo_config('DMO_HISTORICAL_POLICY_PINS');
    if($raw===''||strlen($raw)>16000)return null;
    try{$pins=json_decode($raw,true,4,JSON_THROW_ON_ERROR);}catch(Throwable $e){return null;}
    if(!is_array($pins)||array_is_list($pins)||count($pins)>100)return null;
    $pin=$pins[$version]??null;
    if(!is_string($pin)||!preg_match('/^[a-f0-9]{64}$/D',$pin))return null;
    $doc=dmo_policy_load($version);
    return $doc!==null&&hash_equals($doc['sha256'],$pin)?$doc:null;
}
function dmo_policy_request_eligible(PDO $db,int $requestId,string $version): bool
{
    $current=dmo_policy_current();
    if($current===null)return false;
    $record=dmo_policy_receipt_record($db,$requestId);
    if($record['state']==='invalid'||($record['state']==='recorded'&&!hash_equals($record['version'],$version)))return false;
    $receipt=$record['sha256'];
    if(hash_equals($current['version'],$version)){
        // Preserve current-version legacy records, but never infer a
        // historical acknowledgement when the version changes.
        return $receipt===null||hash_equals($current['sha256'],$receipt);
    }
    $archived=dmo_policy_archived($version);
    return $receipt!==null&&$archived!==null&&hash_equals($archived['sha256'],$receipt);
}

/** Identical policy selection for JSON and server-rendered HTML. */
function dmo_policy_public_reference(mixed $version,mixed $sha): ?array
{
    if($version===null&&$sha===null)return dmo_policy_current();
    if(!is_string($version)||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,39}$/D',$version)||
        !is_string($sha)||!preg_match('/^[a-f0-9]{64}$/D',$sha))return null;
    $doc=$version===dmo_config('DMO_REQUEST_POLICY_VERSION')?dmo_policy_current():dmo_policy_archived($version);
    return $doc!==null&&hash_equals($doc['sha256'],$sha)?$doc:null;
}
