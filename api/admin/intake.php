<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/admin.php';
require_once dirname(__DIR__,2).'/app/requests.php';
try{
    $db=dmo_database();$admin=dmo_admin_require_session($db);
    if(($_SERVER['REQUEST_METHOD']??'')==='GET')dmo_json(200,['ok'=>true,'intake'=>dmo_intake_state($db)]);
    $input=dmo_auth_json_request();dmo_auth_verify_csrf($admin);
    if(!dmo_intake_control_enabled())dmo_json(503,['ok'=>false,'error'=>'intake_control_disabled']);
    if(count($input)!==4||array_diff(array_keys($input),['expected_version','paused','capacity','confirm_change'])!==[]||
        !is_int($input['expected_version']??null)||$input['expected_version']<0||
        !is_bool($input['paused']??null)||!is_int($input['capacity']??null)||$input['capacity']<1||$input['capacity']>100||
        ($input['confirm_change']??null)!==true)dmo_json(422,['ok'=>false,'error'=>'invalid_intake_settings']);
    if(!dmo_auth_rate_limit($db,'intake-settings',(string)$admin['user_id'],15,60))dmo_json(429,['ok'=>false,'error'=>'too_many_attempts']);
    $db->beginTransaction();
    dmo_intake_lock($db);
    $prior=dmo_intake_settings($db);
    if($prior['version']!==$input['expected_version']){
        $db->rollBack();dmo_json(409,['ok'=>false,'error'=>'intake_settings_changed']);
    }
    if($prior['version']===0||$prior['paused']!==$input['paused']||$prior['capacity']!==$input['capacity']){
        $q=$db->prepare("INSERT INTO dmo_audit_log (actor_id,action,entity_type,entity_id,details_json)
            VALUES (:actor,'intake.settings_changed','studio_intake',1,:details)");
        $q->execute(['actor'=>$admin['user_id'],'details'=>json_encode(['paused'=>$input['paused'],'capacity'=>$input['capacity']],JSON_THROW_ON_ERROR)]);
    }
    $db->commit();
    dmo_json(200,['ok'=>true,'intake'=>dmo_intake_state($db)]);
}catch(Throwable $e){
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    dmo_log_problem($e,'admin intake control');
    dmo_json(503,['ok'=>false,'error'=>'intake_control_unavailable']);
}
