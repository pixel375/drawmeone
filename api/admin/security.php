<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/admin.php';
try{
    $db=dmo_database();$session=dmo_admin_require_session($db);$id=(int)$session['user_id'];
    if(($_SERVER['REQUEST_METHOD']??'')==='GET')dmo_json(200,['ok'=>true]+dmo_admin_security_summary($db,$id));
    $input=dmo_auth_json_request();dmo_auth_verify_csrf($session);
    $action=$input['action']??null;$change=$action==='change_password';
    $allowed=$change?['action','current_password','new_password']:['action','current_password'];
    $current=$input['current_password']??null;$new=$input['new_password']??null;
    if(!in_array($action,['change_password','revoke_other_sessions'],true)||count($input)!==count($allowed)||array_diff(array_keys($input),$allowed)||
        !is_string($current)||strlen($current)<1||strlen($current)>128||
        ($change&&(!is_string($new)||strlen($new)<16||strlen($new)>72||!preg_match('//u',$new)||preg_match('/[\\x00-\\x1f\\x7f]/',$new))))
        dmo_json(422,['ok'=>false,'error'=>'invalid_security_request']);
    if(!dmo_auth_rate_limit($db,'admin-security',(string)$id,5,15))dmo_json(429,['ok'=>false,'error'=>'too_many_attempts']);
    $db->beginTransaction();
    $q=$db->prepare("SELECT password_hash FROM dmo_users WHERE id=:id AND role='admin' AND account_status='active' AND email_verified_at IS NOT NULL FOR UPDATE");
    $q->execute(['id'=>$id]);$hash=$q->fetchColumn();
    $q=$db->prepare("SELECT id FROM dmo_sessions WHERE id=:session AND user_id=:id AND session_hash=:hash AND expires_at>UTC_TIMESTAMP() AND last_seen_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) FOR UPDATE");
    $q->execute(['session'=>$session['session_id'],'id'=>$id,'hash'=>$session['session_hash']]);
    if(!is_string($hash)||$q->fetchColumn()===false){$db->rollBack();dmo_json(401,['ok'=>false,'error'=>'admin_login_required']);}
    if(!password_verify($current,$hash)){$db->rollBack();dmo_json(403,['ok'=>false,'error'=>'current_password_incorrect']);}
    if($change){
        if(password_verify($new,$hash)){$db->rollBack();dmo_json(422,['ok'=>false,'error'=>'new_password_must_differ']);}
        $newHash=password_hash($new,defined('PASSWORD_ARGON2ID')?PASSWORD_ARGON2ID:PASSWORD_DEFAULT);
        $db->prepare('UPDATE dmo_users SET password_hash=:hash WHERE id=:id')->execute(['hash'=>$newHash,'id'=>$id]);
        $q=$db->prepare('DELETE FROM dmo_sessions WHERE user_id=:id');$q->execute(['id'=>$id]);
    }else{
        $q=$db->prepare('DELETE FROM dmo_sessions WHERE user_id=:id AND id<>:session');$q->execute(['id'=>$id,'session'=>$session['session_id']]);
    }
    $revoked=$q->rowCount();
    dmo_admin_audit($db,$id,$change?'admin.password_changed':'admin.other_sessions_revoked');
    $db->commit();
    if($change)dmo_admin_cookie('',time()-3600);
    dmo_json(200,['ok'=>true,'requires_sign_in'=>$change,'sessions_revoked'=>$revoked,'status'=>$change?'password_changed':'other_sessions_revoked']);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();dmo_log_problem($e,'admin security');dmo_json(503,['ok'=>false,'error'=>'admin_security_unavailable']);}
