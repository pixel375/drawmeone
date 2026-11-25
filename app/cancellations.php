<?php
declare(strict_types=1);
require_once __DIR__.'/checkout.php';
require_once __DIR__.'/deliveries.php';

function dmo_cancellation_enabled(): bool {
    return dmo_orders_admin_enabled()&&dmo_config('DMO_CANCELLATION_ENABLED')==='1'&&dmo_config('DMO_CANCELLATION_TERMS_APPROVED')==='1';
}
function dmo_withdrawal_enabled(): bool {
    return dmo_auth_configured()&&dmo_config('DMO_CANCELLATION_ENABLED')==='1'&&dmo_config('DMO_CANCELLATION_TERMS_APPROVED')==='1';
}
function dmo_withdrawal_eligible(PDO $db,int $request,string $status): bool {
    if(!in_array($status,['submitted','clarification_needed'],true))return false;
    $q=$db->prepare("SELECT (EXISTS(SELECT 1 FROM dmo_quotes WHERE request_id=:q AND status='accepted') OR EXISTS(SELECT 1 FROM dmo_orders WHERE request_id=:o))");
    $q->execute(['q'=>$request,'o'=>$request]);return (int)$q->fetchColumn()===0;
}
function dmo_cancel_order_eligible(PDO $db,int $request,int $order): bool {
    // Require both known media and checkout schemas: absence must not hide dependencies.
    if(!dmo_checkout_schema_ready($db)||!dmo_delivery_schema_ready($db)||!dmo_review_schema_ready($db))return false;
    $q=$db->prepare("SELECT o.id FROM dmo_orders o JOIN dmo_commission_requests r ON r.id=o.request_id
        JOIN dmo_quotes q ON q.id=o.accepted_quote_id AND q.request_id=r.id
        WHERE o.id=:order AND r.id=:request AND r.status IN ('submitted','clarification_needed')
        AND q.status='accepted' AND o.status='awaiting_arrangement' AND o.payment_status='unverified'
        AND ".dmo_order_exception_sql()." IS NULL
        AND NOT EXISTS(SELECT 1 FROM dmo_payments p WHERE p.order_id=o.id AND p.status<>'rejected')
        AND NOT EXISTS(SELECT 1 FROM dmo_checkout_links l WHERE l.order_id=o.id AND l.status<>'revoked')
        AND NOT EXISTS(SELECT 1 FROM dmo_delivery_drafts d WHERE d.order_id=o.id)
        AND NOT EXISTS(SELECT 1 FROM dmo_deliverables f WHERE f.order_id=o.id)
        AND NOT EXISTS(SELECT 1 FROM dmo_artwork_reviews v WHERE v.order_id=o.id)");
    $q->execute(['order'=>$order,'request'=>$request]);return $q->fetchColumn()!==false;
}
function dmo_cancel_order_apply(PDO $db,array $admin,int $request,array $input): never {
    if(!dmo_cancellation_enabled())dmo_json(503,['ok'=>false,'error'=>'cancellation_disabled']);
    $allowed=['action','request_id','order_id','reason','confirm_no_funds','confirm_agreement'];
    if(count($input)!==count($allowed)||array_diff(array_keys($input),$allowed)!==[]||
        !in_array($input['reason']??null,['customer_requested','mutual_agreement','artist_unavailable'],true)||
        ($input['confirm_no_funds']??null)!==true||($input['confirm_agreement']??null)!==true)
        dmo_json(422,['ok'=>false,'error'=>'explicit_cancellation_confirmation_required']);
    $order=dmo_workflow_id($input['order_id']??null);
    if(!dmo_auth_rate_limit($db,'cancel-unpaid-order',(string)$admin['user_id'],12,60))dmo_json(429,['ok'=>false,'error'=>'too_many_attempts']);
    $db->beginTransaction();
    try{
        // Same serialization order as manual reconciliation and invoice issuance.
        if($db->query("SELECT id FROM dmo_users WHERE role='admin' ORDER BY id LIMIT 1 FOR UPDATE")->fetchColumn()===false)
            throw new RuntimeException('Cancellation unavailable');
        $q=$db->prepare('SELECT id FROM dmo_commission_requests WHERE id=:id FOR UPDATE');$q->execute(['id'=>$request]);
        if($q->fetchColumn()===false)throw new DmoPaymentConflict('request_not_found');
        $q=$db->prepare('SELECT id FROM dmo_orders WHERE id=:id AND request_id=:request FOR UPDATE');$q->execute(['id'=>$order,'request'=>$request]);
        if($q->fetchColumn()===false||!dmo_cancel_order_eligible($db,$request,$order))throw new DmoPaymentConflict('order_not_cancellable');
        $db->prepare("UPDATE dmo_orders SET status='cancelled' WHERE id=:id")->execute(['id'=>$order]);
        $db->prepare("UPDATE dmo_commission_requests SET status='cancelled' WHERE id=:id")->execute(['id'=>$request]);
        dmo_workflow_audit($db,(int)$admin['user_id'],$request,'order.unpaid_cancelled',['order_id'=>$order,'reason'=>$input['reason'],'money_moved'=>false]);
        $db->commit();
    }catch(DmoPaymentConflict $e){$db->rollBack();dmo_json(409,['ok'=>false,'error'=>$e->getMessage()]);}
    catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    dmo_json(200,['ok'=>true,'status'=>'cancelled','money_moved'=>false]);
}
