<?php
declare(strict_types=1);
require_once __DIR__ . '/commission-workflow.php';

const DMO_DELIVERY_DRAFT_LIMIT=4;

function dmo_delivery_schema_ready(PDO $db): bool
{
    $q=$db->prepare('SELECT version FROM dmo_schema_migrations WHERE version=:version');
    $q->execute(['version'=>'004_delivery_drafts']);
    return $q->fetchColumn()==='004_delivery_drafts';
}

function dmo_delivery_staging_enabled(PDO $db): bool
{
    return dmo_orders_admin_enabled() && dmo_delivery_schema_ready($db) &&
        dmo_config('DMO_DELIVERY_DRAFTS_ENABLED')==='1';
}

function dmo_delivery_storage_root(): string
{
    $root=dirname(dmo_artwork_storage_root()).'/drawmeone-deliveries';
    if(is_link($root))throw new RuntimeException('Unsafe delivery root');
    return $root;
}

function dmo_delivery_file(string $key): string
{
    if(!preg_match('~^[a-f0-9]{32}/display\\.webp$~D',$key))throw new InvalidArgumentException('Invalid delivery key');
    $path=dmo_delivery_storage_root().'/'.$key;
    if(is_link(dirname($path))||is_link($path))throw new RuntimeException('Unsafe delivery path');
    return $path;
}

function dmo_delivery_order_eligible(PDO $db,int $requestId,int $orderId): bool
{
    $q=$db->prepare("SELECT r.status AS request_status,o.status AS order_status,r.policy_version FROM dmo_orders o " .
        "JOIN dmo_commission_requests r ON r.id=o.request_id WHERE o.id=:order AND r.id=:request LIMIT 1");
    $q->execute(['order'=>$orderId,'request'=>$requestId]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    return is_array($row) && dmo_messages_request_open($row['request_status']) &&
        in_array($row['order_status'],['awaiting_arrangement','in_progress','ready_for_delivery','queued','drawing','awaiting_feedback','revision'],true) &&
        hash_equals(dmo_requests_policy_version(),$row['policy_version']);
}

function dmo_delivery_drafts(PDO $db,int $requestId): array
{
    if(!dmo_delivery_schema_ready($db))return [];
    $q=$db->prepare('SELECT d.id,d.order_id,d.pixel_width,d.pixel_height,d.byte_size,d.created_at, EXISTS(SELECT 1 FROM dmo_deliverables f WHERE f.order_id=d.order_id AND f.storage_key=d.storage_key) AS released FROM dmo_delivery_drafts d WHERE d.request_id=:id ORDER BY d.id DESC LIMIT 12');
    $q->execute(['id'=>$requestId]);
    return array_map(static fn($r)=>['id'=>(int)$r['id'],'order_id'=>(int)$r['order_id'],
        'width'=>(int)$r['pixel_width'],'height'=>(int)$r['pixel_height'],'bytes'=>(int)$r['byte_size'],
        'created_at'=>$r['created_at'],'released'=>(bool)$r['released']],$q->fetchAll(PDO::FETCH_ASSOC));
}

/** Delete a staged file, not a customer reference or public artwork. */
function dmo_delivery_remove(PDO $db,array $admin,int $requestId,array $input): never
{
    if(count($input)!==4 || array_diff(array_keys($input),
        ['action','request_id','draft_id','confirm_remove'])!==[] ||
        ($input['confirm_remove']??null)!==true)
        dmo_json(422,['ok'=>false,'error'=>'explicit_draft_removal_required']);
    if(!dmo_delivery_staging_enabled($db))
        dmo_json(503,['ok'=>false,'error'=>'delivery_staging_disabled']);
    $id=dmo_workflow_id($input['draft_id']??null);
    if(!dmo_auth_rate_limit($db,'delivery-remove',(string)$admin['user_id'],16,60))
        dmo_json(429,['ok'=>false,'error'=>'too_many_attempts']);
    $db->beginTransaction();
    try{
        $lock=$db->prepare('SELECT id FROM dmo_commission_requests WHERE id=:id FOR UPDATE');
        $lock->execute(['id'=>$requestId]);
        if($lock->fetchColumn()===false){
            $db->rollBack();dmo_json(404,['ok'=>false,'error'=>'draft_not_found']);
        }
        $q=$db->prepare('SELECT order_id,storage_key FROM dmo_delivery_drafts WHERE id=:id AND request_id=:request FOR UPDATE');
        $q->execute(['id'=>$id,'request'=>$requestId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row)){
            $db->rollBack();dmo_json(404,['ok'=>false,'error'=>'draft_not_found']);
        }
        $released=$db->prepare('SELECT id FROM dmo_deliverables WHERE order_id=:order AND storage_key=:key LIMIT 1');
        $released->execute(['order'=>$row['order_id'],'key'=>$row['storage_key']]);
        if($released->fetchColumn()!==false){
            $db->rollBack();dmo_json(409,['ok'=>false,'error'=>'released_artwork_immutable']);
        }
        if(dmo_review_schema_ready($db)){
            $review=$db->prepare("SELECT id FROM dmo_artwork_reviews WHERE draft_id=:id AND status IN ('pending','approved') LIMIT 1");
            $review->execute(['id'=>$id]);
            if($review->fetchColumn()!==false){$db->rollBack();dmo_json(409,['ok'=>false,'error'=>'reviewed_draft_protected']);}
        }
        $path=dmo_delivery_file($row['storage_key']);
        $db->prepare('DELETE FROM dmo_delivery_drafts WHERE id=:id AND request_id=:request')
            ->execute(['id'=>$id,'request'=>$requestId]);
        dmo_workflow_audit($db,(int)$admin['user_id'],$requestId,'delivery.draft_removed',
            ['draft_id'=>$id,'order_id'=>(int)$row['order_id']]);
        $db->commit();
    }catch(Throwable $error){
        if($db->inTransaction())$db->rollBack();
        throw $error;
    }
    // Revoke database access first. A failed filesystem cleanup leaves only
    // an inaccessible orphan, never an active download link.
    $clean=(!file_exists($path)||@unlink($path));
    if($clean && is_dir(dirname($path)))$clean=@rmdir(dirname($path));
    dmo_json(200,['ok'=>true,'draft_removed'=>true,'storage_cleanup_complete'=>$clean]);
}

function dmo_delivery_release_ready(PDO $db): bool
{
    return dmo_delivery_schema_ready($db) && dmo_quote_policy_ready() &&
        dmo_config('DMO_DELIVERY_RELEASE_ENABLED')==='1' &&
        dmo_config('DMO_DELIVERY_TERMS_APPROVED')==='1';
}
function dmo_fulfillment_enabled(PDO $db): bool
{
    return dmo_delivery_release_ready($db) && dmo_orders_admin_enabled();
}
function dmo_fulfillment_payment_sql(): string
{
    return "o.payment_status='paid' AND EXISTS (SELECT 1 FROM dmo_payments p
        WHERE p.order_id=o.id AND p.status='verified' AND p.verified_at IS NOT NULL
        AND p.verified_by IS NOT NULL AND p.currency=q.currency AND p.amount_minor=q.total_minor
        AND p.provider_reference LIKE 'manual-hmac-v1:%')";
}
function dmo_fulfillment_options(PDO $db,int $requestId): array
{
    if(!dmo_fulfillment_enabled($db))return [];
    $q=$db->prepare("SELECT o.id,o.status FROM dmo_orders o JOIN dmo_quotes q ON q.id=o.accepted_quote_id AND q.request_id=o.request_id
        JOIN dmo_commission_requests r ON r.id=o.request_id JOIN dmo_users u ON u.id=r.customer_id
        WHERE r.id=:id AND r.status IN ('submitted','clarification_needed') AND r.policy_version=:policy
        AND u.role='customer' AND u.account_status='active' AND u.email_verified_at IS NOT NULL
        AND q.status='accepted' AND ".dmo_fulfillment_payment_sql());
    $q->execute(['id'=>$requestId,'policy'=>dmo_requests_policy_version()]);
    $options=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
        $next=match($row['status']){
            'awaiting_arrangement'=>['in_progress'],
            'in_progress'=>['ready_for_delivery'],
            'ready_for_delivery'=>['in_progress'],
            default=>[],
        };
        $review=dmo_review_latest($db,(int)$row['id']);
        if($review!==null&&$review['status']!=='approved')$next=array_values(array_diff($next,['ready_for_delivery']));
        $options[]=['order_id'=>(int)$row['id'],'status'=>$row['status'],'next'=>$next,
            'can_release'=>$row['status']==='ready_for_delivery'];
    }
    return $options;
}
final class DmoFulfillmentConflict extends RuntimeException {}
function dmo_fulfillment_apply(PDO $db,array $admin,int $requestId,array $input): never
{
    if(!dmo_fulfillment_enabled($db))dmo_json(503,['ok'=>false,'error'=>'fulfillment_disabled']);
    $release=$input['action']==='release_delivery';
    $fields=$release?['action','request_id','order_id','draft_id','confirm_final']:
        ['action','request_id','order_id','expected_status','status'];
    if(count($input)!==count($fields)||array_diff(array_keys($input),$fields)!==[]||
        ($release&&($input['confirm_final']??null)!==true))
        dmo_json(422,['ok'=>false,'error'=>'invalid_fulfillment_fields']);
    $orderId=dmo_workflow_id($input['order_id']??null);
    $draftId=$release?dmo_workflow_id($input['draft_id']??null):null;
    if(!$release&&(!in_array($input['expected_status']??null,['awaiting_arrangement','in_progress','ready_for_delivery'],true)||
        !in_array($input['status']??null,['in_progress','ready_for_delivery'],true)))
        dmo_json(422,['ok'=>false,'error'=>'invalid_production_transition']);
    if(!dmo_auth_rate_limit($db,'fulfillment',(string)$admin['user_id'],20,60))
        dmo_json(429,['ok'=>false,'error'=>'too_many_attempts']);
    $db->beginTransaction();
    try{
        $lock=$db->prepare('SELECT id FROM dmo_commission_requests WHERE id=:id FOR UPDATE');
        $lock->execute(['id'=>$requestId]);
        if($lock->fetchColumn()===false)throw new DmoFulfillmentConflict('request_not_found');
        $order=$db->prepare('SELECT id FROM dmo_orders WHERE id=:order AND request_id=:request FOR UPDATE');
        $order->execute(['order'=>$orderId,'request'=>$requestId]);
        if($order->fetchColumn()===false)throw new DmoFulfillmentConflict('order_not_eligible');
        $option=null;
        foreach(dmo_fulfillment_options($db,$requestId) as $candidate)
            if($candidate['order_id']===$orderId)$option=$candidate;
        if($option===null)throw new DmoFulfillmentConflict('verified_payment_and_policy_required');
        if($release){
            if(!$option['can_release'])throw new DmoFulfillmentConflict('order_not_ready');
            $review=dmo_review_latest($db,$orderId);
            if($review!==null&&($review['status']!=='approved'||(int)$review['draft_id']!==$draftId))throw new DmoFulfillmentConflict('approved_draft_required');
            $q=$db->prepare('SELECT storage_key,sha256,byte_size FROM dmo_delivery_drafts WHERE id=:id AND request_id=:request AND order_id=:order FOR UPDATE');
            $q->execute(['id'=>$draftId,'request'=>$requestId,'order'=>$orderId]);
            $draft=$q->fetch(PDO::FETCH_ASSOC);
            if(!is_array($draft))throw new DmoFulfillmentConflict('draft_not_found');
            $file=dmo_delivery_file($draft['storage_key']);
            if(!is_file($file)||!is_readable($file)||filesize($file)!==(int)$draft['byte_size']||
                !hash_equals($draft['sha256'],(string)hash_file('sha256',$file)))
                throw new DmoFulfillmentConflict('draft_integrity_failed');
            $existing=$db->prepare('SELECT id FROM dmo_deliverables WHERE order_id=:id LIMIT 1');
            $existing->execute(['id'=>$orderId]);
            if($existing->fetchColumn()!==false)throw new DmoFulfillmentConflict('delivery_already_exists');
            $db->prepare("INSERT INTO dmo_deliverables (order_id,storage_key,download_name,mime_type,byte_size,revision_number)
                VALUES (:order,:key,:name,'image/webp',:bytes,1)")->execute([
                'order'=>$orderId,'key'=>$draft['storage_key'],'name'=>'commission-'.$orderId.'.webp','bytes'=>$draft['byte_size']]);
            $deliveryId=(int)$db->lastInsertId();
            $db->prepare("UPDATE dmo_orders SET status='completed' WHERE id=:id")->execute(['id'=>$orderId]);
            $db->prepare("UPDATE dmo_commission_requests SET status='completed' WHERE id=:id")->execute(['id'=>$requestId]);
            dmo_workflow_audit($db,(int)$admin['user_id'],$requestId,'delivery.released',
                ['order_id'=>$orderId,'draft_id'=>$draftId,'delivery_id'=>$deliveryId]);
            $result=['delivery_id'=>$deliveryId,'status'=>'completed','customer_visible'=>true];
        }else{
            if($input['expected_status']!==$option['status']||!in_array($input['status'],$option['next'],true))
                throw new DmoFulfillmentConflict('production_status_conflict');
            if($input['status']==='ready_for_delivery'){
                $review=dmo_review_latest($db,$orderId);
                if($review!==null&&$review['status']!=='approved')throw new DmoFulfillmentConflict('customer_review_pending');
                $q=$db->prepare('SELECT id FROM dmo_delivery_drafts WHERE order_id=:order AND request_id=:request LIMIT 1');
                $q->execute(['order'=>$orderId,'request'=>$requestId]);
                if($q->fetchColumn()===false)throw new DmoFulfillmentConflict('stage_artwork_first');
            }
            $db->prepare('UPDATE dmo_orders SET status=:status WHERE id=:id')->execute(['status'=>$input['status'],'id'=>$orderId]);
            dmo_workflow_audit($db,(int)$admin['user_id'],$requestId,'order.production_changed',
                ['order_id'=>$orderId,'from'=>$option['status'],'to'=>$input['status']]);
            $result=['status'=>$input['status'],'customer_visible'=>false];
        }
        $db->commit();
    }catch(DmoFulfillmentConflict $error){
        if($db->inTransaction())$db->rollBack();
        dmo_json(409,['ok'=>false,'error'=>$error->getMessage()]);
    }catch(Throwable $error){
        if($db->inTransaction())$db->rollBack();throw $error;
    }
    dmo_json($release?201:200,['ok'=>true,'payment_status'=>'paid','money_moved'=>false]+$result);
}
function dmo_delivery_customer_query(): string
{
    return " FROM dmo_deliverables f JOIN dmo_orders o ON o.id=f.order_id
        JOIN dmo_quotes q ON q.id=o.accepted_quote_id AND q.request_id=o.request_id
        JOIN dmo_commission_requests r ON r.id=o.request_id
        JOIN dmo_delivery_drafts d ON d.order_id=o.id AND d.request_id=r.id AND d.storage_key=f.storage_key
        WHERE r.customer_id=:customer AND o.status='completed' AND r.status='completed'
        AND q.status='accepted' AND f.mime_type='image/webp' AND ".dmo_fulfillment_payment_sql();
}
function dmo_delivery_customer_rows(PDO $db,int $customerId,int $requestId): array
{
    if(!dmo_delivery_release_ready($db))return [];
    $q=$db->prepare('SELECT f.id,f.order_id,f.byte_size,f.created_at,d.pixel_width,d.pixel_height'.
        dmo_delivery_customer_query().' AND r.id=:request ORDER BY f.id DESC LIMIT 4');
    $q->execute(['customer'=>$customerId,'request'=>$requestId]);
    return array_map(static fn($r)=>['id'=>(int)$r['id'],'order_id'=>(int)$r['order_id'],
        'bytes'=>(int)$r['byte_size'],'width'=>(int)$r['pixel_width'],'height'=>(int)$r['pixel_height'],
        'created_at'=>$r['created_at']],$q->fetchAll(PDO::FETCH_ASSOC));
}

require_once __DIR__.'/artwork-reviews.php';
