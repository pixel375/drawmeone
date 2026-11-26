<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/privacy.php';
try{
    $db=dmo_database();$admin=dmo_admin_require_session($db);
    if(($_SERVER['REQUEST_METHOD']??'')==='GET'){
        $before=dmo_privacy_cursor($_GET['before']??null);
        $view=$_GET['view']??'open';
        if(!in_array($view,['open','open_7d','open_30d','closed'],true))dmo_json(422,['ok'=>false,'error'=>'invalid_privacy_view']);
        if($view==='closed')dmo_json(200,['ok'=>true]+dmo_privacy_history($db,null,$before,true));
        $age=match($view){'open_7d'=>7,'open_30d'=>30,default=>null};
        $ageCondition=$age===null?'':' AND first_event.created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL '.$age.' DAY)';
        $source=" FROM dmo_audit_log a JOIN dmo_users u ON u.id=a.entity_id
          LEFT JOIN dmo_audit_log first_event ON first_event.id=COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details_json,'$.case_id')) AS UNSIGNED),a.id)
          AND first_event.entity_type='user' AND first_event.entity_id=u.id AND first_event.action='privacy.case'
          WHERE a.entity_type='user' AND a.action='privacy.case' AND u.role='customer'
          AND a.id=(SELECT MAX(x.id) FROM dmo_audit_log x WHERE x.entity_type='user' AND x.entity_id=u.id AND x.action='privacy.case')
          AND JSON_UNQUOTE(JSON_EXTRACT(a.details_json,'$.status')) IN ('submitted','in_review')".$ageCondition;
        $db->beginTransaction();
        $total=(int)$db->query('SELECT COUNT(*)'.$source)->fetchColumn();
        $q=$db->prepare("SELECT a.id,a.details_json,a.created_at,u.id AS customer_id,u.display_name,u.email,first_event.created_at AS submitted_at,
          CASE WHEN first_event.created_at<=UTC_TIMESTAMP() THEN TIMESTAMPDIFF(DAY,first_event.created_at,UTC_TIMESTAMP()) ELSE NULL END AS age_days".$source.($before===null?'':' AND a.id<:before').' ORDER BY a.id DESC LIMIT 26');
        $q->execute($before===null?[]:['before'=>$before]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        $more=count($rows)>25;if($more)array_pop($rows);
        $cases=array_map(static fn($r)=>dmo_privacy_case($r)+['customer_id'=>(int)$r['customer_id'],'display_name'=>$r['display_name'],'email'=>$r['email'],
            'submitted_at'=>$r['submitted_at'],'age_days'=>$r['age_days']===null?null:(int)$r['age_days']],$rows);
        $db->commit();
        dmo_json(200,['ok'=>true,'cases'=>$cases,'matching_count'=>$total,'next_before'=>$more?$cases[count($cases)-1]['version']:null]);
    }
    $input=dmo_auth_json_request();dmo_auth_verify_csrf($admin);
    if(count($input)!==5||array_diff(array_keys($input),['customer_id','expected_version','status','reply','confirm_record'])!==[]||
        !is_int($input['customer_id']??null)||$input['customer_id']<1||!is_int($input['expected_version']??null)||
        !in_array($input['status']??null,['in_review','resolved','declined'],true)||($input['confirm_record']??null)!==true)
        dmo_json(422,['ok'=>false,'error'=>'invalid_privacy_decision']);
    $reply=dmo_privacy_text($input['reply']??null,1000,$input['status']==='in_review'?0:10);
    if(!dmo_auth_rate_limit($db,'privacy-admin',(string)$admin['user_id'],30,60))dmo_json(429,['ok'=>false,'error'=>'too_many_attempts']);
    $db->beginTransaction();$user=$input['customer_id'];
    // Cases remain actionable even after an account is disabled.
    $q=$db->prepare("SELECT id FROM dmo_users WHERE id=:id AND role='customer' FOR UPDATE");$q->execute(['id'=>$user]);
    $case=$q->fetchColumn()===false?null:dmo_privacy_latest($db,$user);
    if(!dmo_privacy_open($case)||$case['version']!==$input['expected_version']||($case['status']==='in_review'&&$input['status']==='in_review')){
        $db->rollBack();dmo_json(409,['ok'=>false,'error'=>'privacy_request_changed']);
    }
    $case['status']=$input['status'];$case['reply']=$reply;unset($case['version'],$case['updated_at']);
    dmo_privacy_append($db,(int)$admin['user_id'],$user,$case);$db->commit();
    dmo_json(200,['ok'=>true,'case'=>dmo_privacy_latest($db,$user),'data_deleted'=>false,'data_changed'=>false]);
}catch(Throwable $e){
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    dmo_log_problem($e,'admin privacy');dmo_json(503,['ok'=>false,'error'=>'privacy_service_unavailable']);
}
