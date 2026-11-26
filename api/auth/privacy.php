<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/privacy.php';
try{
    $db=dmo_database();$session=dmo_customer_require($db);$user=(int)$session['user_id'];
    if(($_SERVER['REQUEST_METHOD']??'')==='GET'){
        $before=dmo_privacy_cursor($_GET['before']??null);
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();
        $result=['ok'=>true,'enabled'=>dmo_privacy_enabled(),'case'=>dmo_privacy_latest($db,$user),'history'=>dmo_privacy_history($db,$user,$before,false)];
        $db->commit();dmo_json(200,$result);
    }
    $input=dmo_auth_json_request();dmo_auth_verify_csrf($session);
    if(!dmo_privacy_enabled())dmo_json(503,['ok'=>false,'error'=>'privacy_self_service_disabled']);
    $action=$input['action']??null;
    $allowed=match($action){'export'=>['action','current_password'],'submit'=>['action','current_password','kind','note'],'withdraw'=>['action','current_password','expected_version'],default=>[]};
    if(!$allowed||count($input)!==count($allowed)||array_diff(array_keys($input),$allowed)!==[])dmo_json(422,['ok'=>false,'error'=>'invalid_privacy_action']);
    if($action==='submit'){
        if(!in_array($input['kind']??null,['access','correction','deletion'],true))dmo_json(422,['ok'=>false,'error'=>'invalid_privacy_kind']);
        $note=dmo_privacy_text($input['note']??null,1000);
    }
    if(!dmo_auth_rate_limit($db,'privacy-self-service',(string)$user,8,60))dmo_json(429,['ok'=>false,'error'=>'too_many_attempts']);
    $db->beginTransaction();$profile=dmo_privacy_reauth($db,$session,$input['current_password']??null);
    if($action==='export'){
        $export=dmo_privacy_export($db,$user,$profile);
        $db->commit();dmo_json(200,['ok'=>true,'export'=>$export]);
    }
    $latest=dmo_privacy_latest($db,$user);
    if($action==='submit'){
        if(dmo_privacy_open($latest)){$db->rollBack();dmo_json(409,['ok'=>false,'error'=>'privacy_request_already_open']);}
        dmo_privacy_append($db,$user,$user,['kind'=>$input['kind'],'status'=>'submitted','note'=>$note,'reply'=>'']);
    }else{
        if(!dmo_privacy_open($latest)||$latest['version']!==($input['expected_version']??null)){$db->rollBack();dmo_json(409,['ok'=>false,'error'=>'privacy_request_changed']);}
        $latest['status']='withdrawn';unset($latest['version'],$latest['updated_at']);dmo_privacy_append($db,$user,$user,$latest);
    }
    $recorded=dmo_privacy_latest($db,$user);
    $db->commit();dmo_json(200,['ok'=>true,'case'=>$recorded,'data_deleted'=>false]);
}catch(LengthException $e){
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    dmo_json(413,['ok'=>false,'error'=>'export_requires_manual_review']);
}catch(Throwable $e){
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    dmo_log_problem($e,'customer privacy');dmo_json(503,['ok'=>false,'error'=>'privacy_service_unavailable']);
}
