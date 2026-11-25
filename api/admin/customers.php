<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/admin.php';
dmo_reject_non_get();
try{
    $db=dmo_database();dmo_admin_require_session($db);
    $search=$_GET['q']??'';$state=$_GET['state']??'all';$before=$_GET['before']??null;
    if(!is_string($search)||strlen($search)>160||!preg_match('//u',$search)||preg_match('/[\\x00-\\x1f\\x7f]/',$search)||
       !in_array($state,['all','verified','unverified','inactive'],true)||
       ($before!==null&&(!is_string($before)||!preg_match('/^[1-9][0-9]{0,17}$/D',$before))))
       dmo_json(422,['ok'=>false,'error'=>'invalid_customer_filter']);
    $where=["u.role='customer'"];$params=[];$search=trim($search);
    if($state==='verified')$where[]="u.account_status='active' AND u.email_verified_at IS NOT NULL";
    if($state==='unverified')$where[]="u.email_verified_at IS NULL AND u.account_status IN ('active','pending')";
    if($state==='inactive')$where[]="u.account_status NOT IN ('active','pending')";
    if($search!==''){
        $where[]="(LOCATE(:name,u.display_name)>0 OR LOCATE(:email,u.email)>0 OR EXISTS(SELECT 1 FROM dmo_commission_requests r WHERE r.customer_id=u.id AND LOCATE(:ref,r.public_ref)>0))";
        $params=['name'=>$search,'email'=>$search,'ref'=>$search];
    }
    if($before!==null){$where[]='u.id<:before';$params['before']=(int)$before;}
    $q=$db->prepare("SELECT u.id,u.display_name,u.email,u.account_status,(u.email_verified_at IS NOT NULL) AS email_verified,
       (SELECT COUNT(*) FROM dmo_commission_requests r WHERE r.customer_id=u.id) AS request_count,
       (SELECT MAX(r.submitted_at) FROM dmo_commission_requests r WHERE r.customer_id=u.id) AS last_request_at
       FROM dmo_users u WHERE ".implode(' AND ',$where)." ORDER BY u.id DESC LIMIT 26");
    $q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $more=count($rows)>25;if($more)array_pop($rows);
    foreach($rows as &$row){$row['id']=(int)$row['id'];$row['request_count']=(int)$row['request_count'];$row['email_verified']=(bool)$row['email_verified'];}unset($row);
    dmo_json(200,['ok'=>true,'readonly'=>true,'customers'=>$rows,'next_before'=>$more?$rows[count($rows)-1]['id']:null]);
}catch(Throwable $e){dmo_log_problem($e,'customer directory');dmo_json(503,['ok'=>false,'error'=>'customer_directory_unavailable']);}
