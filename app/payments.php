<?php
declare(strict_types=1);
require_once __DIR__ . '/commission-workflow.php';

function dmo_manual_payments_enabled(): bool
{
    return dmo_orders_admin_enabled() &&
        dmo_config('DMO_MANUAL_PAYMENT_ENABLED')==='1' &&
        dmo_config('DMO_MANUAL_PAYMENT_TERMS_APPROVED')==='1';
}

function dmo_payment_methods(): array
{
    return ['bank_transfer','paypal','stripe','other'];
}

function dmo_payment_fingerprint(string $method,mixed $reference): string
{
    if(!in_array($method,dmo_payment_methods(),true) ||
        !is_string($reference) || !preg_match('/^[A-Za-z0-9._:\/-]{6,120}$/D',$reference))
        dmo_json(422,['ok'=>false,'error'=>'invalid_transaction_reference']);
    return 'manual-hmac-v1:'.hash_hmac('sha256',$method.'|'.strtolower($reference),dmo_config('DMO_APP_KEY'));
}

function dmo_payment_admin_rows(PDO $db,int $requestId): array
{
    $q=$db->prepare('SELECT p.id,p.order_id,p.method,p.currency,p.amount_minor,p.status,p.verified_at,p.created_at ' .
        'FROM dmo_payments p JOIN dmo_orders o ON o.id=p.order_id WHERE o.request_id=:id ORDER BY p.id DESC LIMIT 30');
    $q->execute(['id'=>$requestId]);
    return array_map(static fn($r)=>['id'=>(int)$r['id'],'order_id'=>(int)$r['order_id'],
        'method'=>$r['method'],'currency'=>$r['currency'],'amount_minor'=>(int)$r['amount_minor'],
        'status'=>$r['status'],'verified_at'=>$r['verified_at'],'created_at'=>$r['created_at']],$q->fetchAll(PDO::FETCH_ASSOC));
}

final class DmoPaymentConflict extends RuntimeException {}

function dmo_payment_reconcile(PDO $db,array $admin,int $requestId,array $input): never
{
    if(!dmo_manual_payments_enabled())dmo_json(503,['ok'=>false,'error'=>'manual_payment_disabled']);
    $action=$input['action'];$record=$action==='record_payment_evidence';
    $reject=$action==='reject_payment_evidence';
    $allowed=$record?['action','request_id','order_id','method','reference','currency','amount_minor']:
        ($reject?['action','request_id','order_id','payment_id','confirm_rejection']:
        ['action','request_id','order_id','payment_id','method','reference','currency','amount_minor','confirm_received']);
    if(count($input)!==count($allowed)||array_diff(array_keys($input),$allowed)!==[])
        dmo_json(422,['ok'=>false,'error'=>'invalid_payment_fields']);
    $orderId=dmo_workflow_id($input['order_id']??null);
    $paymentId=$record?null:dmo_workflow_id($input['payment_id']??null);
    $fingerprint=null;
    if(!$reject){
        if(!is_string($input['method']??null)||!in_array($input['currency']??null,['CAD','USD'],true)||
            !is_int($input['amount_minor']??null)||$input['amount_minor']<1||
            (!$record&&($input['confirm_received']??null)!==true))
            dmo_json(422,['ok'=>false,'error'=>'invalid_payment_evidence']);
        $fingerprint=dmo_payment_fingerprint($input['method'],$input['reference']??null);
    }elseif(($input['confirm_rejection']??null)!==true){
        dmo_json(422,['ok'=>false,'error'=>'explicit_rejection_required']);
    }
    if(!dmo_auth_rate_limit($db,'manual-payment-reconcile',(string)$admin['user_id'],20,60))
        dmo_json(429,['ok'=>false,'error'=>'too_many_attempts']);
    $db->beginTransaction();
    try{
        // Serialize all manual reconciliation operations, including cross-order
        // duplicate checks. Lock the same stable first admin row before requests.
        $mutex=$db->query("SELECT id FROM dmo_users WHERE role='admin' ORDER BY id LIMIT 1 FOR UPDATE");
        if($mutex->fetchColumn()===false)throw new DmoPaymentConflict('reconciliation_unavailable');
        $lock=$db->prepare('SELECT status,policy_version FROM dmo_commission_requests WHERE id=:id FOR UPDATE');
        $lock->execute(['id'=>$requestId]);$request=$lock->fetch(PDO::FETCH_ASSOC);
        if(!is_array($request)||!dmo_messages_request_open($request['status'])||
            !hash_equals(dmo_requests_policy_version(),$request['policy_version']))
            throw new DmoPaymentConflict('request_not_eligible');
        $q=$db->prepare("SELECT o.status,o.payment_status,q.currency,q.total_minor," . dmo_order_exception_sql() . " AS exception_action FROM dmo_orders o " .
            "JOIN dmo_quotes q ON q.id=o.accepted_quote_id AND q.request_id=o.request_id AND q.status='accepted' " .
            "WHERE o.id=:order AND o.request_id=:request FOR UPDATE");
        $q->execute(['order'=>$orderId,'request'=>$requestId]);$order=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($order)||$order['status']!=='awaiting_arrangement'||$order['payment_status']!=='unverified'||dmo_order_exception_status($order['exception_action'])!==null)
            throw new DmoPaymentConflict('order_not_eligible');
        if(!$reject&&($input['currency']!==$order['currency']||$input['amount_minor']!==(int)$order['total_minor']))
            throw new DmoPaymentConflict('full_quote_amount_required');
        if($record){
            $duplicate=$db->prepare("SELECT id FROM dmo_payments WHERE provider_reference=:ref OR (order_id=:order AND status IN ('pending_verification','verified')) LIMIT 1");
            $duplicate->execute(['ref'=>$fingerprint,'order'=>$orderId]);
            if($duplicate->fetchColumn()!==false)throw new DmoPaymentConflict('payment_evidence_duplicate');
            $db->prepare("INSERT INTO dmo_payments (order_id,method,currency,amount_minor,provider_reference,status) VALUES (:order,:method,:currency,:amount,:ref,'pending_verification')")
                ->execute(['order'=>$orderId,'method'=>$input['method'],'currency'=>$order['currency'],'amount'=>(int)$order['total_minor'],'ref'=>$fingerprint]);
            $paymentId=(int)$db->lastInsertId();$status='pending_verification';
        }else{
            $p=$db->prepare("SELECT method,currency,amount_minor,provider_reference,status FROM dmo_payments WHERE id=:id AND order_id=:order FOR UPDATE");
            $p->execute(['id'=>$paymentId,'order'=>$orderId]);$payment=$p->fetch(PDO::FETCH_ASSOC);
            if(!is_array($payment)||$payment['status']!=='pending_verification'||
                !str_starts_with((string)$payment['provider_reference'],'manual-hmac-v1:'))
                throw new DmoPaymentConflict('payment_not_pending');
            if(!$reject&&($payment['method']!==$input['method']||$payment['currency']!==$order['currency']||
                (int)$payment['amount_minor']!==(int)$order['total_minor']||!hash_equals($payment['provider_reference'],$fingerprint)))
                throw new DmoPaymentConflict('payment_evidence_mismatch');
            $status=$reject?'rejected':'verified';
            $db->prepare("UPDATE dmo_payments SET status=:status,verified_by=:actor,verified_at=UTC_TIMESTAMP() WHERE id=:id")
                ->execute(['status'=>$status,'actor'=>(int)$admin['user_id'],'id'=>$paymentId]);
            if(!$reject)$db->prepare("UPDATE dmo_orders SET payment_status='paid' WHERE id=:id")->execute(['id'=>$orderId]);
        }
        dmo_workflow_audit($db,(int)$admin['user_id'],$requestId,'payment.manual_'.$status,
            ['payment_id'=>$paymentId,'order_id'=>$orderId]);
        $db->commit();
    }catch(DmoPaymentConflict $error){
        if($db->inTransaction())$db->rollBack();
        dmo_json(409,['ok'=>false,'error'=>$error->getMessage()]);
    }catch(Throwable $error){
        if($db->inTransaction())$db->rollBack();throw $error;
    }
    dmo_json($record?201:200,['ok'=>true,'payment_id'=>$paymentId,'status'=>$status,
        'payment_status'=>$status==='verified'?'paid':'unverified','provider_verified'=>false,'money_moved'=>false]);
}

function dmo_payment_exceptions_enabled(): bool
{
    return dmo_orders_admin_enabled() &&
        dmo_config('DMO_PAYMENT_EXCEPTIONS_ENABLED')==='1' &&
        dmo_config('DMO_PAYMENT_EXCEPTIONS_TERMS_APPROVED')==='1';
}
function dmo_payment_exception_apply(PDO $db,array $admin,int $requestId,array $input): never
{
    if(!dmo_payment_exceptions_enabled())dmo_json(503,['ok'=>false,'error'=>'payment_exceptions_disabled']);
    $decision=$input['decision']??null;
    $reason=$input['reason']??null;
    $expected=$input['expected_state']??null;
    if(count($input)!==7||array_diff(array_keys($input),['action','request_id','order_id','decision','reason','expected_state','confirm_external_check'])!==[]||
        !in_array($decision,['hold','restore','refund'],true)||
        !in_array($expected,['paid','on_hold'],true)||
        !in_array($reason,['dispute','reversal','reconciliation_error','settled','full_external_refund'],true)||
        ($input['confirm_external_check']??null)!==true||
        ($decision==='hold'&&!in_array($reason,['dispute','reversal','reconciliation_error'],true))||
        ($decision==='restore'&&$reason!=='settled')||
        ($decision==='refund'&&$reason!=='full_external_refund'))
        dmo_json(422,['ok'=>false,'error'=>'invalid_payment_exception']);
    $orderId=dmo_workflow_id($input['order_id']??null);
    if(!dmo_auth_rate_limit($db,'payment-exception',(string)$admin['user_id'],15,60))
        dmo_json(429,['ok'=>false,'error'=>'too_many_attempts']);
    $db->beginTransaction();
    try{
        $lock=$db->prepare('SELECT id FROM dmo_commission_requests WHERE id=:id FOR UPDATE');
        $lock->execute(['id'=>$requestId]);
        if($lock->fetchColumn()===false)throw new DmoPaymentConflict('request_not_found');
        $q=$db->prepare("SELECT o.payment_status,o.status,q.currency,q.total_minor,".dmo_order_exception_sql()." AS exception_action
            FROM dmo_orders o JOIN dmo_quotes q ON q.id=o.accepted_quote_id AND q.request_id=o.request_id
            WHERE o.id=:order AND o.request_id=:request AND q.status='accepted' FOR UPDATE");
        $q->execute(['order'=>$orderId,'request'=>$requestId]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))throw new DmoPaymentConflict('order_not_eligible');
        $state=dmo_order_exception_status($row['exception_action'])??$row['payment_status'];
        if($state!==$expected||($decision==='hold'&&$state!=='paid')||
            ($decision==='restore'&&$state!=='on_hold')||($decision==='refund'&&!in_array($state,['paid','on_hold'],true)))
            throw new DmoPaymentConflict('payment_exception_state_changed');
        $p=$db->prepare("SELECT id FROM dmo_payments WHERE order_id=:order AND status='verified'
            AND verified_by IS NOT NULL AND verified_at IS NOT NULL AND currency=:currency AND amount_minor=:amount
            AND provider_reference LIKE 'manual-hmac-v1:%' LIMIT 1 FOR UPDATE");
        $p->execute(['order'=>$orderId,'currency'=>$row['currency'],'amount'=>(int)$row['total_minor']]);
        $payment=$p->fetchColumn();
        if($payment===false)throw new DmoPaymentConflict('original_payment_evidence_required');
        $action=match($decision){'hold'=>'payment.access_held','restore'=>'payment.access_restored','refund'=>'payment.full_refund_recorded'};
        // Preserve the original receipt record; access state is a separate, audited decision.
        $db->prepare('UPDATE dmo_orders SET payment_status=:status WHERE id=:id')
            ->execute(['status'=>$decision==='restore'?'paid':'unverified','id'=>$orderId]);
        dmo_workflow_audit($db,(int)$admin['user_id'],$requestId,$action,
            ['order_id'=>$orderId,'payment_id'=>(int)$payment,'reason'=>$reason]);
        $db->commit();
    }catch(DmoPaymentConflict $e){if($db->inTransaction())$db->rollBack();dmo_json(409,['ok'=>false,'error'=>$e->getMessage()]);}
    catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    dmo_json(200,['ok'=>true,'payment_status'=>match($decision){'hold'=>'on_hold','restore'=>'paid','refund'=>'refunded'},'money_moved'=>false]);
}
