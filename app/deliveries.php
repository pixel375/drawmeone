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
    $q=$db->prepare('SELECT id,order_id,pixel_width,pixel_height,byte_size,created_at FROM dmo_delivery_drafts WHERE request_id=:id ORDER BY id DESC LIMIT 12');
    $q->execute(['id'=>$requestId]);
    return array_map(static fn($r)=>['id'=>(int)$r['id'],'order_id'=>(int)$r['order_id'],
        'width'=>(int)$r['pixel_width'],'height'=>(int)$r['pixel_height'],'bytes'=>(int)$r['byte_size'],
        'created_at'=>$r['created_at']],$q->fetchAll(PDO::FETCH_ASSOC));
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
