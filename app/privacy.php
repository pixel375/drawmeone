<?php
declare(strict_types=1);
require_once __DIR__.'/deliveries.php';

function dmo_privacy_enabled(): bool {return dmo_config('DMO_PRIVACY_SELF_SERVICE_ENABLED')==='1';}
function dmo_privacy_lock_user(PDO $db,int $id): ?array {
    $q=$db->prepare("SELECT id,display_name,email,password_hash FROM dmo_users WHERE id=:id AND role='customer' AND account_status='active' AND email_verified_at IS NOT NULL FOR UPDATE");
    $q->execute(['id'=>$id]);$u=$q->fetch(PDO::FETCH_ASSOC);return is_array($u)?$u:null;
}
function dmo_privacy_reauth(PDO $db,array $session,mixed $password): array {
    if(!is_string($password)||strlen($password)<1||strlen($password)>128){$db->rollBack();dmo_json(422,['ok'=>false,'error'=>'invalid_password']);}
    $user=dmo_privacy_lock_user($db,(int)$session['user_id']);
    $q=$db->prepare('SELECT id FROM dmo_sessions WHERE id=:session AND user_id=:user AND session_hash=:hash AND expires_at>UTC_TIMESTAMP() FOR UPDATE');
    $q->execute(['session'=>$session['session_id'],'user'=>$session['user_id'],'hash'=>$session['session_hash']]);
    if($user===null||$q->fetchColumn()===false){$db->rollBack();dmo_json(401,['ok'=>false,'error'=>'authentication_required']);}
    if(!password_verify($password,$user['password_hash'])){$db->rollBack();dmo_json(403,['ok'=>false,'error'=>'current_password_incorrect']);}
    unset($user['password_hash'],$user['id']);return $user;
}
function dmo_privacy_case(array $row): array {
    $d=json_decode($row['details_json'],true,8,JSON_THROW_ON_ERROR);
    if(!is_array($d)||!in_array($d['kind']??null,['access','correction','deletion'],true)||
        !in_array($d['status']??null,['submitted','in_review','resolved','declined','withdrawn'],true)||
        (isset($d['case_id'])&&(!is_int($d['case_id'])||$d['case_id']<1||$d['case_id']>(int)$row['id']))||
        !is_string($d['note']??'')||!is_string($d['reply']??'')||
        strlen($d['note']??'')>1000||strlen($d['reply']??'')>1000)
        throw new RuntimeException('Invalid privacy case');
    return ['version'=>(int)$row['id'],'case_id'=>(int)($d['case_id']??$row['id']),'kind'=>$d['kind'],'status'=>$d['status'],
        'note'=>(string)($d['note']??''),'reply'=>(string)($d['reply']??''),'updated_at'=>$row['created_at']];
}
function dmo_privacy_latest(PDO $db,int $id): ?array {
    $q=$db->prepare("SELECT id,details_json,created_at FROM dmo_audit_log WHERE entity_type='user' AND entity_id=:id AND action='privacy.case' ORDER BY id DESC LIMIT 1");
    $q->execute(['id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);return is_array($row)?dmo_privacy_case($row):null;
}
function dmo_privacy_open(?array $case): bool {return $case!==null&&in_array($case['status'],['submitted','in_review'],true);}
function dmo_privacy_append(PDO $db,int $actor,int $user,array $data): void {
    $q=$db->prepare("INSERT INTO dmo_audit_log (actor_id,action,entity_type,entity_id,details_json) VALUES (:actor,'privacy.case','user',:user,:details)");
    $q->execute(['actor'=>$actor,'user'=>$user,'details'=>json_encode($data,JSON_THROW_ON_ERROR)]);
}
function dmo_privacy_text(mixed $value,int $max,int $min=0): string {
    if(!is_string($value)||strlen($value)>$max||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)||strlen(trim($value))<$min)
        dmo_json(422,['ok'=>false,'error'=>'invalid_privacy_text']);
    return trim($value);
}

/** Explicit allowlists; no credentials, internal notes, raw provider references or storage paths. */
function dmo_privacy_export(PDO $db,int $user,array $profile,int $rowLimit=10000,int $byteLimit=8388608): array {
    if($rowLimit<1||$rowLimit>10000||$byteLimit<256||$byteLimit>8388608)throw new InvalidArgumentException('Invalid export bounds');
    $spec=[
        'requests'=>"SELECT r.public_ref,r.subject_type,r.brief,r.preferred_contact,r.preferred_deadline,r.status,r.policy_version,r.submitted_at,r.updated_at,r.tier_snapshot_json,t.name AS tier_name FROM dmo_commission_requests r LEFT JOIN dmo_commission_tiers t ON t.id=r.tier_id WHERE r.customer_id=:user ORDER BY r.id",
        'addons'=>"SELECT r.public_ref,a.name,ra.addon_snapshot_json FROM dmo_request_addons ra JOIN dmo_commission_requests r ON r.id=ra.request_id JOIN dmo_addons a ON a.id=ra.addon_id WHERE r.customer_id=:user ORDER BY r.id,a.id",
        'messages'=>"SELECT r.public_ref,m.body,m.created_at,u.role AS sender_role,u.display_name AS sender_name FROM dmo_messages m JOIN dmo_conversations c ON c.id=m.conversation_id JOIN dmo_commission_requests r ON r.id=c.request_id JOIN dmo_users u ON u.id=m.sender_id WHERE r.customer_id=:user AND m.is_internal=0 ORDER BY m.id",
        'quotes'=>"SELECT r.public_ref,q.id,q.version_number,q.currency,q.total_minor,q.scope_description,q.revision_allowance,q.validity_ends_at,q.status,q.sent_at,q.accepted_at FROM dmo_quotes q JOIN dmo_commission_requests r ON r.id=q.request_id WHERE r.customer_id=:user AND q.sent_at IS NOT NULL AND q.status IN ('sent','accepted','declined','superseded') ORDER BY q.id",
        'quote_lines'=>"SELECT q.id AS quote_id,l.description,l.amount_minor FROM dmo_quote_lines l JOIN dmo_quotes q ON q.id=l.quote_id JOIN dmo_commission_requests r ON r.id=q.request_id WHERE r.customer_id=:user AND q.sent_at IS NOT NULL AND q.status IN ('sent','accepted','declined','superseded') ORDER BY q.id,l.sort_order,l.id",
        'orders'=>"SELECT r.public_ref,o.id,o.accepted_quote_id,o.status,o.payment_status,o.agreed_deadline,o.created_at,o.updated_at,".dmo_order_exception_sql()." AS exception_action FROM dmo_orders o JOIN dmo_commission_requests r ON r.id=o.request_id WHERE r.customer_id=:user ORDER BY o.id",
        'payments'=>"SELECT r.public_ref,p.order_id,p.method,p.currency,p.amount_minor,p.status,p.verified_at,p.created_at FROM dmo_payments p JOIN dmo_orders o ON o.id=p.order_id JOIN dmo_commission_requests r ON r.id=o.request_id WHERE r.customer_id=:user ORDER BY p.id",
        'policy_receipts'=>"SELECT r.public_ref,a.created_at,JSON_UNQUOTE(JSON_EXTRACT(a.details_json,'$.policy_version')) AS policy_version,JSON_UNQUOTE(JSON_EXTRACT(a.details_json,'$.policy_sha256')) AS policy_sha256 FROM dmo_audit_log a JOIN dmo_commission_requests r ON r.id=a.entity_id WHERE r.customer_id=:user AND a.entity_type='commission_request' AND a.action IN ('request.submitted','quote.customer_accepted') ORDER BY a.id",
        'account_policy'=>"SELECT created_at,JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.policy_version')) AS policy_version,JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.policy_sha256')) AS policy_sha256 FROM dmo_audit_log WHERE entity_type='user' AND entity_id=:user AND action='account.created' ORDER BY id",
        'privacy_requests'=>"SELECT id,details_json,created_at FROM dmo_audit_log WHERE entity_type='user' AND entity_id=:user AND action='privacy.case' ORDER BY id",
    ];
    if(dmo_refs_schema_ready($db))$spec['reference_metadata']="SELECT r.public_ref,d.id,d.pixel_width,d.pixel_height,d.byte_size,d.created_at FROM dmo_request_attachments d JOIN dmo_commission_requests r ON r.id=d.request_id WHERE r.customer_id=:user ORDER BY d.id";
    if(dmo_review_schema_ready($db))$spec['reviews']="SELECT r.public_ref,v.order_id,v.round_number,v.status,v.feedback,v.created_at,v.responded_at FROM dmo_artwork_reviews v JOIN dmo_orders o ON o.id=v.order_id JOIN dmo_commission_requests r ON r.id=o.request_id WHERE r.customer_id=:user ORDER BY v.id";
    if(dmo_delivery_schema_ready($db))$spec['delivery_metadata']="SELECT r.public_ref,f.id,f.order_id,f.mime_type,f.byte_size,f.created_at FROM dmo_deliverables f JOIN dmo_orders o ON o.id=f.order_id JOIN dmo_commission_requests r ON r.id=o.request_id WHERE r.customer_id=:user ORDER BY f.id";
    $export=['format'=>'drawmeone-customer-records-v1','generated_at'=>gmdate('c'),'profile'=>$profile,
        'scope'=>'Customer-visible structured records and selected account/financial metadata. Image bytes, credentials, internal records and other personal data requiring individual review are not included. Request additional access for a fuller response.'];
    // Count the envelope, collection keys and row separators too. Avoid
    // PDO buffering an entire large result before the per-export cap applies.
    $flags=JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES;
    $bytes=strlen(json_encode(['ok'=>true,'export'=>$export],$flags));$count=0;
    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,false);
    try{
    foreach($spec as $name=>$sql){
        $bytes+=strlen(json_encode($name,$flags))+4;
        $q=$db->prepare($sql.' LIMIT '.($rowLimit+1));$q->execute(['user'=>$user]);$items=[];
        try{
        while($row=$q->fetch(PDO::FETCH_ASSOC)){
            if(++$count>$rowLimit)throw new LengthException('Export exceeds self-service row limit');
            if($name==='requests')$row=dmo_requests_historical_tier($row);
            if($name==='addons'){$row['name']=dmo_requests_snapshot_name($row['addon_snapshot_json'],$row['name']);unset($row['addon_snapshot_json']);}
            if($name==='orders'){$row['payment_status']=dmo_order_exception_status($row['exception_action'])??$row['payment_status'];unset($row['exception_action']);}
            if($name==='privacy_requests')$row=dmo_privacy_case($row);
            $bytes+=strlen(json_encode($row,$flags))+1;
            if($bytes>$byteLimit)throw new LengthException('Export exceeds self-service byte limit');
            $items[]=$row;
        }
        }finally{$q->closeCursor();}
        $export[$name]=$items;
    }
    }finally{$db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY,true);}
    if($bytes>$byteLimit)throw new LengthException('Export exceeds self-service byte limit');
    return $export;
}

/** One latest event per case, including earlier resolved/withdrawn cases.
 * The original event's ID is the case ID. Subsequent events carry that ID.
 * All joins are scoped to the same customer; cursor operates on event ID.
 */
function dmo_privacy_history(PDO $db,?int $user,?int $before,bool $closedOnly): array
{
    $caseId=static fn(string $alias): string=>"COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(".$alias.".details_json,'$.case_id')) AS UNSIGNED),".$alias.".id)";
    $sql="SELECT a.id,a.details_json,a.created_at,u.id AS customer_id,u.display_name,u.email
        FROM dmo_audit_log a JOIN dmo_users u ON u.id=a.entity_id
        WHERE a.entity_type='user' AND a.action='privacy.case' AND u.role='customer'
        AND NOT EXISTS(SELECT 1 FROM dmo_audit_log x WHERE x.entity_type='user' AND x.action='privacy.case'
            AND x.entity_id=a.entity_id AND x.id>a.id AND ".$caseId('x')."=".$caseId('a').")";
    $params=[];
    if($user!==null){$sql.=" AND a.entity_id=:user";$params['user']=$user;}
    if($before!==null){$sql.=" AND a.id<:before";$params['before']=$before;}
    if($closedOnly)$sql.=" AND JSON_UNQUOTE(JSON_EXTRACT(a.details_json,'$.status')) IN ('resolved','declined','withdrawn')";
    $q=$db->prepare($sql.' ORDER BY a.id DESC LIMIT 26');$q->execute($params);
    $rows=$q->fetchAll(PDO::FETCH_ASSOC);$more=count($rows)>25;
    if($more)array_pop($rows);
    $cases=[];
    foreach($rows as $row){
        $item=dmo_privacy_case($row);
        if($user===null)$item+=['customer_id'=>(int)$row['customer_id'],'display_name'=>$row['display_name'],'email'=>$row['email']];
        $cases[]=$item;
    }
    return ['cases'=>$cases,'next_before'=>$more?$cases[count($cases)-1]['version']:null];
}
function dmo_privacy_cursor(mixed $value): ?int
{
    if($value===null)return null;
    if(!is_string($value)||!preg_match('/^[1-9][0-9]{0,17}$/D',$value))dmo_json(422,['ok'=>false,'error'=>'invalid_cursor']);
    return (int)$value;
}
