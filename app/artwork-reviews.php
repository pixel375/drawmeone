<?php
declare(strict_types=1);
require_once __DIR__.'/deliveries.php';

function dmo_review_schema_ready(PDO $db): bool {
    $q=$db->query("SELECT version FROM dmo_schema_migrations WHERE version='005_artwork_reviews'");
    return $q->fetchColumn()==='005_artwork_reviews';
}
function dmo_review_enabled(PDO $db): bool {
    return dmo_delivery_release_ready($db) && dmo_review_schema_ready($db) &&
        dmo_config('DMO_ARTWORK_REVIEW_ENABLED')==='1';
}
function dmo_review_latest(PDO $db,int $orderId): ?array {
    if(!dmo_review_schema_ready($db))return null;
    $q=$db->prepare('SELECT id,draft_id,round_number,status,feedback,responded_at FROM dmo_artwork_reviews WHERE order_id=:id ORDER BY round_number DESC LIMIT 1');
    $q->execute(['id'=>$orderId]);$r=$q->fetch(PDO::FETCH_ASSOC);
    return is_array($r)?$r:null;
}
function dmo_review_used(PDO $db,int $orderId): int {
    $q=$db->prepare("SELECT COUNT(*) FROM dmo_artwork_reviews WHERE order_id=:id AND status='revision_requested'");
    $q->execute(['id'=>$orderId]);return (int)$q->fetchColumn();
}
function dmo_review_orders(PDO $db,int $requestId): array {
    if(!dmo_review_schema_ready($db))return [];
    $q=$db->prepare("SELECT o.id,o.status,q.revision_allowance,r.policy_version FROM dmo_orders o JOIN dmo_quotes q ON q.id=o.accepted_quote_id AND q.request_id=o.request_id
      JOIN dmo_commission_requests r ON r.id=o.request_id JOIN dmo_users u ON u.id=r.customer_id
      WHERE r.id=:id AND q.status='accepted' AND u.account_status='active' AND u.email_verified_at IS NOT NULL AND ".dmo_fulfillment_payment_sql());
    $q->execute(['id'=>$requestId]);$result=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $o){
        $id=(int)$o['id'];$latest=dmo_review_latest($db,$id);$used=dmo_review_used($db,$id);
        $result[]=['order_id'=>$id,'revision_allowance'=>(int)$o['revision_allowance'],'revisions_used'=>$used,
            'can_offer'=>dmo_review_enabled($db)&&dmo_orders_admin_enabled()&&dmo_policy_request_eligible($db,$requestId,$o['policy_version'])&&$o['status']==='in_progress'&&
                ($latest===null||$latest['status']==='revision_requested'),
            'latest'=>$latest===null?null:['id'=>(int)$latest['id'],'draft_id'=>$latest['draft_id']===null?null:(int)$latest['draft_id'],
                'round'=>(int)$latest['round_number'],'status'=>$latest['status'],'feedback'=>$latest['feedback'],'responded_at'=>$latest['responded_at']]];
    }
    return $result;
}
function dmo_review_offer(PDO $db,array $admin,int $requestId,array $input): never {
    if(!dmo_review_enabled($db)||!dmo_orders_admin_enabled())dmo_json(503,['ok'=>false,'error'=>'review_disabled']);
    if(count($input)!==4||array_diff(array_keys($input),['action','request_id','order_id','draft_id'])!==[])
        dmo_json(422,['ok'=>false,'error'=>'invalid_review_offer']);
    $orderId=dmo_workflow_id($input['order_id']??null);$draftId=dmo_workflow_id($input['draft_id']??null);
    if(!dmo_auth_rate_limit($db,'artwork-review-offer',(string)$admin['user_id'],20,60))dmo_json(429,['ok'=>false,'error'=>'too_many_attempts']);
    $db->beginTransaction();
    try{
        $lock=$db->prepare('SELECT id FROM dmo_commission_requests WHERE id=:id FOR UPDATE');$lock->execute(['id'=>$requestId]);
        if($lock->fetchColumn()===false)throw new DmoFulfillmentConflict('request_not_found');
        $option=null;foreach(dmo_review_orders($db,$requestId) as $item)if($item['order_id']===$orderId)$option=$item;
        if($option===null||!$option['can_offer'])throw new DmoFulfillmentConflict('review_not_available');
        $eligible=false;foreach(dmo_fulfillment_options($db,$requestId) as $item)if($item['order_id']===$orderId&&$item['status']==='in_progress')$eligible=true;
        if(!$eligible)throw new DmoFulfillmentConflict('order_not_eligible');
        $q=$db->prepare('SELECT storage_key,sha256,byte_size FROM dmo_delivery_drafts WHERE id=:id AND order_id=:order AND request_id=:request');
        $q->execute(['id'=>$draftId,'order'=>$orderId,'request'=>$requestId]);$draft=$q->fetch(PDO::FETCH_ASSOC);
        $old=$db->prepare('SELECT id FROM dmo_artwork_reviews WHERE order_id=:order AND draft_id=:draft LIMIT 1');
        $old->execute(['order'=>$orderId,'draft'=>$draftId]);
        if(!is_array($draft)||$old->fetchColumn()!==false)throw new DmoFulfillmentConflict('new_draft_required');
        if(!dmo_delivery_draft_intact($draft))throw new DmoFulfillmentConflict('draft_integrity_failed');
        $round=($option['latest']['round']??0)+1;
        if($round>26)throw new DmoFulfillmentConflict('review_limit');
        $db->prepare("INSERT INTO dmo_artwork_reviews (order_id,draft_id,round_number,status) VALUES (:order,:draft,:round,'pending')")
            ->execute(['order'=>$orderId,'draft'=>$draftId,'round'=>$round]);
        $id=(int)$db->lastInsertId();
        dmo_workflow_audit($db,(int)$admin['user_id'],$requestId,'artwork.review_offered',['review_id'=>$id,'draft_id'=>$draftId]);
        $db->commit();
    }catch(DmoFulfillmentConflict $e){if($db->inTransaction())$db->rollBack();dmo_json(409,['ok'=>false,'error'=>$e->getMessage()]);}
    catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    dmo_json(201,['ok'=>true,'review_id'=>$id,'status'=>'pending']);
}
function dmo_review_owner_query(): string {
    return " FROM dmo_artwork_reviews v JOIN dmo_orders o ON o.id=v.order_id
      JOIN dmo_commission_requests r ON r.id=o.request_id
      JOIN dmo_quotes q ON q.id=o.accepted_quote_id AND q.request_id=r.id
      JOIN dmo_delivery_drafts d ON d.id=v.draft_id AND d.order_id=o.id AND d.request_id=r.id
      WHERE r.customer_id=:customer AND r.status IN ('submitted','clarification_needed')
      AND o.status='in_progress' AND v.status='pending' AND q.status='accepted'
      AND ".dmo_fulfillment_payment_sql();
}
function dmo_review_respond(PDO $db,array $session,array $input): never {
    if(!dmo_review_enabled($db))dmo_json(503,['ok'=>false,'error'=>'review_disabled']);
    if(count($input)!==3||array_diff(array_keys($input),['review_id','decision','feedback'])!==[]||
        !in_array($input['decision']??null,['approve','revise'],true)||
        !is_string($input['feedback']??null)||strlen($input['feedback'])>2000)
        dmo_json(422,['ok'=>false,'error'=>'invalid_review_response']);
    $feedback=trim($input['feedback']);$revise=$input['decision']==='revise';
    if(($revise&&strlen($feedback)<3)||(!$revise&&$feedback!==''))dmo_json(422,['ok'=>false,'error'=>'invalid_feedback']);
    $id=dmo_workflow_id($input['review_id']??null);
    if(!dmo_auth_rate_limit($db,'artwork-review',(string)$session['user_id'],15,60))dmo_json(429,['ok'=>false,'error'=>'too_many_attempts']);
    $args=['customer'=>(int)$session['user_id'],'id'=>$id];
    $db->beginTransaction();
    try{
        $q=$db->prepare('SELECT r.id AS request_id'.dmo_review_owner_query().' AND v.id=:id');$q->execute($args);$request=$q->fetchColumn();
        if($request===false)throw new DmoFulfillmentConflict('review_not_available');
        $lock=$db->prepare('SELECT id FROM dmo_commission_requests WHERE id=:id FOR UPDATE');$lock->execute(['id'=>$request]);
        $q=$db->prepare('SELECT o.id AS order_id,q.revision_allowance,r.policy_version,d.storage_key,d.sha256,d.byte_size'.dmo_review_owner_query().' AND v.id=:id FOR UPDATE');$q->execute($args);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)||!dmo_policy_request_eligible($db,(int)$request,$row['policy_version']))throw new DmoFulfillmentConflict('review_not_available');
        if(!dmo_delivery_draft_intact($row))throw new DmoFulfillmentConflict('draft_integrity_failed');
        if($revise&&dmo_review_used($db,(int)$row['order_id'])>=(int)$row['revision_allowance'])throw new DmoFulfillmentConflict('included_revisions_exhausted');
        $status=$revise?'revision_requested':'approved';
        $db->prepare('UPDATE dmo_artwork_reviews SET status=:status,feedback=:feedback,responded_at=UTC_TIMESTAMP() WHERE id=:id')
            ->execute(['status'=>$status,'feedback'=>$revise?$feedback:null,'id'=>$id]);
        dmo_workflow_audit($db,(int)$session['user_id'],(int)$request,'artwork.review_'.$status,['review_id'=>$id]);
        $db->commit();
    }catch(DmoFulfillmentConflict $e){if($db->inTransaction())$db->rollBack();dmo_json(409,['ok'=>false,'error'=>$e->getMessage()]);}
    catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    dmo_json(200,['ok'=>true,'status'=>$status]);
}
