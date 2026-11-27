<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/guest-requests.php';
$input=dmo_auth_json_request();
try{
    $db=dmo_database();
    if(!dmo_guest_ready($db)||dmo_studio_load($db)['settings']['accepting']!==true)dmo_json(503,['ok'=>false,'error'=>'commissions_closed']);
    $p=dmo_guest_payload($input);$token=$input['turnstile_token']??null;
    if($p===null||!is_string($token)||strlen($token)<10||strlen($token)>2048)dmo_json(422,['ok'=>false,'error'=>'invalid_request']);
    if(!dmo_auth_rate_limit($db,'guest-ip','',8,15)||!dmo_auth_rate_limit($db,'guest-email',$p['email'],3,60)||!dmo_auth_rate_limit($db,'guest-global','studio',40,60))dmo_json(429,['ok'=>false,'error'=>'too_many_requests']);
    if(!dmo_auth_verify_turnstile($token,'guest_request'))dmo_json(422,['ok'=>false,'error'=>'verification_failed']);
    $result=dmo_guest_store($db,$p);
    if($result['result']==='closed')dmo_json(503,['ok'=>false,'error'=>'commissions_closed']);
    if($result['result']==='conflict')dmo_json(409,['ok'=>false,'error'=>'request_changed']);
    if($result['result']==='duplicate')dmo_json(200,['ok'=>true,'received'=>true]);
    if($result['result']!=='created')throw new RuntimeException('Unexpected guest store result');
    // Persistence precedes notification. Fixed recipient and no guest PII in
    // email; a failed/uncertain notification cannot discard or resend a request.
    $sent=false;
    try{$sent=dmo_mail_send('support@drawmeone.com','New Draw Me One enquiry','A new drawing enquiry is in your private Artist Desk. Sign in at https://drawmeone.com/studio-admin/index.php to read it.');}
    catch(Throwable $e){dmo_log_problem($e,'guest notice');}
    try{$db->prepare("UPDATE dmo_guest_requests SET notice_state=:state WHERE id=:id AND notice_state='pending'")->execute(['state'=>$sent?'sent':'failed','id'=>$result['id']]);}
    catch(Throwable $e){dmo_log_problem($e,'guest notice state');}
    dmo_json(201,['ok'=>true,'received'=>true]);
}catch(Throwable $e){dmo_log_problem($e,'guest request');dmo_json(503,['ok'=>false,'error'=>'request_unavailable']);}
