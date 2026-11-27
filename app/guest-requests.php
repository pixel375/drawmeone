<?php
declare(strict_types=1);
require_once __DIR__.'/studio-v2.php';

/** Only guest request fields; never store captcha, raw IP, user agent, or card data. */
function dmo_guest_payload(mixed $raw): ?array {
    if(!is_array($raw)||array_is_list($raw)||array_diff(array_keys($raw),['name','email','brief','budget','operation_key','turnstile_token','website'])!==[])return null;
    if(array_key_exists('website',$raw)&&$raw['website']!=='')return null;
    $out=[];
    foreach(['name'=>[1,100],'email'=>[4,254],'brief'=>[5,3000],'budget'=>[0,120]] as $key=>[$min,$max]){
        $v=$raw[$key]??($key==='budget'?'':null);
        if(!is_string($v)||strlen($v)>$max||preg_match('//u',$v)!==1)return null;
        $v=trim($v);$pattern=$key==='brief'?'/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u':'/[\x00-\x1f\x7f]/u';
        if(strlen($v)<$min||preg_match($pattern,$v)!==0)return null;
        $out[$key]=$key==='brief'?str_replace(["\r\n","\r"],"\n",$v):$v;
    }
    if(filter_var($out['email'],FILTER_VALIDATE_EMAIL)===false)return null;
    $out['email']=strtolower($out['email']);
    $key=$raw['operation_key']??null;
    if(!is_string($key)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$key))return null;
    $out['operation_hash']=hash_hmac('sha256','guest-operation|'.$key,dmo_config('DMO_APP_KEY'));
    $out['payload_digest']=hash_hmac('sha256','guest-payload|'.json_encode($out,JSON_THROW_ON_ERROR),dmo_config('DMO_APP_KEY'));
    return $out;
}
/** Used only after successful challenge and rate limits. Same lock as availability changes. */
function dmo_guest_store(PDO $db,array $p,bool $challengeVerified=true): array {
    $db->beginTransaction();
    try{
        dmo_studio_lock($db);
        $settings=dmo_studio_load($db)['settings'];
        if(!dmo_guest_policy_ready($db,$settings)||!$settings['accepting']){$db->rollBack();return ['result'=>'closed'];}
        if($settings['guest_captcha']&&!$challengeVerified){$db->rollBack();return ['result'=>'challenge_required'];}
        $q=$db->prepare('SELECT id,payload_digest FROM dmo_guest_requests WHERE operation_hash=:operation_hash LIMIT 1');
        $q->execute(['operation_hash'=>$p['operation_hash']]);$existing=$q->fetch();
        if(is_array($existing)){
            $db->commit();return ['result'=>hash_equals((string)$existing['payload_digest'],$p['payload_digest'])?'duplicate':'conflict'];
        }
        $q=$db->prepare('INSERT INTO dmo_guest_requests(operation_hash,payload_digest,name,email,brief,budget) VALUES (:operation_hash,:payload_digest,:name,:email,:brief,:budget)');
        $q->execute($p);$id=(int)$db->lastInsertId();$db->commit();return ['result'=>'created','id'=>$id];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
