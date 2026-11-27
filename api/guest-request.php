<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/guest-requests.php';
$input=dmo_auth_json_request();
try{
    $db=dmo_database();
    $settings=dmo_studio_load($db)['settings'];
    if(!dmo_guest_policy_ready($db,$settings)||!$settings['accepting'])dmo_json(503,['ok'=>false,'error'=>'commissions_closed']);
    $p=dmo_guest_payload($input);$token=$input['turnstile_token']??null;
    if($p===null||($settings['guest_captcha']&&(!is_string($token)||strlen($token)<10||strlen($token)>2048)))dmo_json(422,['ok'=>false,'error'=>'invalid_request']);
    if(!dmo_auth_rate_limit($db,'guest-ip','',$settings['guest_captcha']?8:3,15)||!dmo_auth_rate_limit($db,'guest-email',$p['email'],3,60)||!dmo_auth_rate_limit($db,'guest-global','studio',$settings['guest_captcha']?40:15,60))dmo_json(429,['ok'=>false,'error'=>'too_many_requests']);
    $verified=false;
    if($settings['guest_captcha']){if(!dmo_auth_verify_turnstile($token,'guest_request'))dmo_json(422,['ok'=>false,'error'=>'verification_failed']);$verified=true;}
    $result=dmo_guest_store($db,$p,$verified);
    if($result['result']==='challenge_required')dmo_json(409,['ok'=>false,'error'=>'verification_required']);
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
