<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/cancellations.php';
try{
    $db=dmo_database();$session=dmo_customer_require($db);
    $input=dmo_auth_json_request();dmo_auth_verify_csrf($session);
    if(!dmo_withdrawal_enabled())dmo_json(503,['ok'=>false,'error'=>'withdrawal_disabled']);
    if(count($input)!==3||array_diff(array_keys($input),['ref','expected_status','confirm_withdrawal'])!==[]||
        !is_string($input['ref']??null)||!preg_match('/^DMO-[a-f0-9]{20}$/D',$input['ref'])||
        !in_array($input['expected_status']??null,['submitted','clarification_needed'],true)||($input['confirm_withdrawal']??null)!==true)
        dmo_json(422,['ok'=>false,'error'=>'explicit_withdrawal_required']);
    if(!dmo_auth_rate_limit($db,'request-withdrawal',(string)$session['user_id'],10,60))dmo_json(429,['ok'=>false,'error'=>'too_many_attempts']);
    $db->beginTransaction();
    $q=$db->prepare('SELECT id,status FROM dmo_commission_requests WHERE public_ref=:ref AND customer_id=:customer FOR UPDATE');
    $q->execute(['ref'=>$input['ref'],'customer'=>$session['user_id']]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!is_array($r)){$db->rollBack();dmo_json(404,['ok'=>false,'error'=>'request_not_found']);}
    $id=(int)$r['id'];
    if($r['status']!==$input['expected_status']||!dmo_withdrawal_eligible($db,$id,$r['status'])){
        $db->rollBack();dmo_json(409,['ok'=>false,'error'=>'request_not_withdrawable']);
    }
    // Preserve all quote records, but invalidate outstanding offers atomically.
    $db->prepare("UPDATE dmo_quotes SET status='superseded' WHERE request_id=:id AND status='sent'")->execute(['id'=>$id]);
    $db->prepare("UPDATE dmo_commission_requests SET status='cancelled' WHERE id=:id")->execute(['id'=>$id]);
    dmo_workflow_audit($db,(int)$session['user_id'],$id,'request.customer_withdrawn',['money_moved'=>false]);
    $db->commit();dmo_json(200,['ok'=>true,'status'=>'cancelled','money_moved'=>false]);
}catch(Throwable $e){
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    dmo_log_problem($e,'customer request withdrawal');dmo_json(503,['ok'=>false,'error'=>'request_service_unavailable']);
}
