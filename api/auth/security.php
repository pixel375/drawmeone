<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/auth.php';
header('Cache-Control: no-store');
dmo_auth_require_enabled();
$input=dmo_auth_json_request();
$action=$input['action']??null;
$change=$action==='change_password';
$allowed=$change?['action','current_password','new_password']:['action','current_password'];
$current=$input['current_password']??null;
$new=$input['new_password']??null;
if(!in_array($action,['change_password','revoke_other_sessions'],true)||
    count($input)!==count($allowed)||array_diff(array_keys($input),$allowed)!==[]||
    !is_string($current)||strlen($current)<1||strlen($current)>128||
    ($change&&(!is_string($new)||!dmo_auth_password($new)||hash_equals($current,$new)))){
    dmo_json(422,['ok'=>false,'error'=>'invalid_security_request']);
}
try{
    $db=dmo_database();$session=dmo_auth_current_session($db);
    if($session===null||$session['role']!=='customer')dmo_json(401,['ok'=>false,'error'=>'authentication_required']);
    dmo_auth_verify_csrf($session);
    $userId=(int)$session['user_id'];
    if(!dmo_auth_rate_limit($db,'account-security',(string)$userId,8,15))
        dmo_json(429,['ok'=>false,'error'=>'too_many_attempts']);
    $db->beginTransaction();
    // Serialize with login and credential changes. Recheck the actual
    // session under the same account lock, not a stale preflight snapshot.
    $q=$db->prepare("SELECT password_hash,email FROM dmo_users WHERE id=:id AND role='customer'
        AND account_status='active' AND email_verified_at IS NOT NULL FOR UPDATE");
    $q->execute(['id'=>$userId]);$user=$q->fetch(PDO::FETCH_ASSOC);
    $check=$db->prepare('SELECT id FROM dmo_sessions WHERE id=:session AND user_id=:user
        AND session_hash=:hash AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
    $check->execute(['session'=>$session['session_id'],'user'=>$userId,'hash'=>$session['session_hash']]);
    if(!is_array($user)||$check->fetchColumn()===false){
        $db->rollBack();dmo_json(401,['ok'=>false,'error'=>'authentication_required']);
    }
    if(!password_verify($current,$user['password_hash'])){
        $db->rollBack();dmo_json(403,['ok'=>false,'error'=>'current_password_incorrect']);
    }
    if($change){
        if(password_verify($new,$user['password_hash'])){
            $db->rollBack();dmo_json(422,['ok'=>false,'error'=>'new_password_must_differ']);
        }
        $hash=password_hash($new,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT);
        $db->prepare('UPDATE dmo_users SET password_hash=:hash WHERE id=:id')->execute(['hash'=>$hash,'id'=>$userId]);
        $db->prepare("UPDATE dmo_auth_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=:id
            AND purpose='reset_password' AND consumed_at IS NULL")->execute(['id'=>$userId]);
        $delete=$db->prepare('DELETE FROM dmo_sessions WHERE user_id=:id');$delete->execute(['id'=>$userId]);
    }else{
        $delete=$db->prepare('DELETE FROM dmo_sessions WHERE user_id=:id AND id<>:session');
        $delete->execute(['id'=>$userId,'session'=>$session['session_id']]);
    }
    $revoked=$delete->rowCount();
    $db->prepare("INSERT INTO dmo_audit_log (actor_id,action,entity_type,entity_id)
        VALUES (:actor,:action,'user',:entity)")->execute(['actor'=>$userId,'entity'=>$userId,
            'action'=>$change?'account.password_changed':'account.other_sessions_revoked']);
    $db->commit();
    if($change){
        dmo_auth_cookie('',time()-3600);
        try{
            dmo_auth_send_email($user['email'],'Draw Me One account password changed',
                "Your account password was changed. All existing sessions were signed out.\n\nIf you did not make this change, contact support@drawmeone.com.\n");
        }catch(Throwable $ignored){error_log('[DrawMeOne] Password-change notification unavailable.');}
    }
    dmo_json(200,['ok'=>true,'status'=>$change?'password_changed':'other_sessions_revoked',
        'sessions_revoked'=>$revoked,'requires_sign_in'=>$change]);
}catch(Throwable $error){
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    dmo_log_problem($error,'customer account security');
    dmo_json(503,['ok'=>false,'error'=>'account_service_unavailable']);
}
