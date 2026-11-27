<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/guest-requests.php';
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');dmo_json(405,['ok'=>false,'error'=>'method_not_allowed']);}
try{
    $db=dmo_database();$admin=dmo_admin_require_session($db);
    if(!dmo_guest_schema_ready($db))dmo_json(503,['ok'=>false,'error'=>'requests_unavailable']);
    if($method==='GET'){
        $view=$_GET['view']??'active';$before=$_GET['before']??null;
        if(!in_array($view,['active','archived'],true)||($before!==null&&(!is_string($before)||!preg_match('/^[1-9][0-9]{0,17}$/D',$before))))dmo_json(422,['ok'=>false,'error'=>'invalid_view']);
        $where=$view==='archived'?"status='archived'":"status IN ('new','read')";
        $sql='SELECT id,name,email,brief,budget,status,notice_state,created_at FROM dmo_guest_requests WHERE '.$where.($before!==null?' AND id<:before':'').' ORDER BY id DESC LIMIT 51';
        $q=$db->prepare($sql);$q->execute($before===null?[]:['before'=>(int)$before]);$rows=$q->fetchAll();$more=count($rows)>50;$rows=array_slice($rows,0,50);
        foreach($rows as &$row)$row['id']=(int)$row['id'];unset($row);
        dmo_json(200,['ok'=>true,'requests'=>$rows,'next_before'=>$more?$rows[count($rows)-1]['id']:null]);
    }
    $input=dmo_auth_json_request();dmo_auth_verify_csrf($admin);
    $id=$input['id']??null;$status=$input['status']??null;$expected=$input['expected']??null;
    if(array_diff(array_keys($input),['id','status','expected'])!==[]||!is_int($id)||$id<1||!in_array($status,['new','read','archived'],true)||!in_array($expected,['new','read','archived'],true)||$status===$expected)dmo_json(422,['ok'=>false,'error'=>'invalid_change']);
    $q=$db->prepare('UPDATE dmo_guest_requests SET status=:status WHERE id=:id AND status=:expected');
    $q->execute(['status'=>$status,'id'=>$id,'expected'=>$expected]);
    if($q->rowCount()!==1)dmo_json(409,['ok'=>false,'error'=>'request_changed']);
    dmo_json(200,['ok'=>true]);
}catch(Throwable $e){dmo_log_problem($e,'artist guest inbox');dmo_json(503,['ok'=>false,'error'=>'requests_unavailable']);}
