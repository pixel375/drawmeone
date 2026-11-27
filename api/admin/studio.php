<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/studio-v2.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');dmo_json(405,['ok'=>false,'error'=>'method_not_allowed']);}
try{
    $db=dmo_database();$admin=dmo_admin_require_session($db);
    if($method==='GET')dmo_json(200,['ok'=>true,'requests_ready'=>dmo_guest_ready($db)]+dmo_studio_load($db));
    $input=dmo_auth_json_request();dmo_auth_verify_csrf($admin);
    if(array_diff(array_keys($input),['version','settings'])!==[]||!is_int($input['version']??null)||$input['version']<0||($settings=dmo_studio_normalize($input['settings']??null))===null)dmo_json(422,['ok'=>false,'error'=>'invalid_settings']);
    $db->beginTransaction();
    dmo_studio_lock($db);
    $current=dmo_studio_load($db);
    if($current['version']!==$input['version']){$db->rollBack();dmo_json(409,['ok'=>false,'error'=>'settings_changed']);}
    $ready=dmo_guest_ready($db);
    if($settings['accepting']&&!$ready){$db->rollBack();dmo_json(503,['ok'=>false,'error'=>'requests_unavailable']);}
    if($current['settings']===$settings){$db->commit();dmo_json(200,['ok'=>true,'requests_ready'=>$ready]+$current);}
    $db->prepare("INSERT INTO dmo_audit_log (actor_id,action,entity_type,entity_id,details_json) VALUES (:actor,'studio.v2.settings','user',:entity,:details)")->execute(['actor'=>$admin['user_id'],'entity'=>$admin['user_id'],'details'=>json_encode($settings,JSON_THROW_ON_ERROR)]);
    $version=(int)$db->lastInsertId();$db->commit();
    dmo_json(200,['ok'=>true,'version'=>$version,'settings'=>$settings,'requests_ready'=>$ready]);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();dmo_log_problem($e,'studio settings');dmo_json(503,['ok'=>false,'error'=>'studio_unavailable']);}
