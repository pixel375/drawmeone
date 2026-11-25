<?php
declare(strict_types=1);
require_once __DIR__ . '/commission-workflow.php';

// Optional, explicit artist-triggered transactional notices. No automatic mail,
// no arbitrary recipient/body, and no payment or order state mutation.
function dmo_notifications_enabled(): bool
{
    return dmo_workflow_writes_enabled() && dmo_quote_policy_ready() &&
        dmo_mail_ready() && dmo_config('DMO_CUSTOMER_NOTIFICATIONS_ENABLED') === '1';
}

function dmo_notification_template(string $kind): array
{
    return match ($kind) {
        'quote' => ['Draw Me One - quote available',
            "A commission quote is available in your private Draw Me One account."],
        'order' => ['Draw Me One - order update',
            "There is an order update in your private Draw Me One account."],
        default => throw new InvalidArgumentException('Unknown notification kind'),
    };
}

function dmo_notification_status(PDO $db,int $requestId,string $kind,int $eventId): string
{
    $q=$db->prepare("SELECT action FROM dmo_audit_log WHERE entity_type='commission_request' " .
        "AND entity_id=:id AND action IN ('notification.reserved','notification.accepted','notification.failed') " .
        "AND JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.kind'))=:kind " .
        "AND JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.event_id'))=:event ORDER BY id DESC LIMIT 1");
    $q->execute(['id'=>$requestId,'kind'=>$kind,'event'=>(string)$eventId]);
    return match ($q->fetchColumn()) {
        'notification.accepted'=>'accepted',
        'notification.failed'=>'failed',
        'notification.reserved'=>'uncertain',
        default=>'not_sent',
    };
}

function dmo_notification_target(PDO $db,int $requestId,string $kind,int $eventId): ?array
{
    $query=$db->prepare("SELECT r.status,r.preferred_contact,r.policy_version,u.email " .
        "FROM dmo_commission_requests r JOIN dmo_users u ON u.id=r.customer_id " .
        "WHERE r.id=:id AND u.role='customer' AND u.account_status='active' " .
        "AND u.email_verified_at IS NOT NULL LIMIT 1");
    $query->execute(['id'=>$requestId]);
    $row=$query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row['preferred_contact']!=='email' ||
        !dmo_messages_request_open((string)$row['status']) ||
        !hash_equals(dmo_requests_policy_version(),(string)$row['policy_version']) ||
        filter_var($row['email'],FILTER_VALIDATE_EMAIL)===false) return null;
    if ($kind==='quote') {
        $event=$db->prepare("SELECT q.id FROM dmo_quotes q WHERE q.request_id=:id AND q.id=:event " .
            "AND q.status='sent' AND q.sent_at IS NOT NULL AND q.validity_ends_at>UTC_TIMESTAMP() " .
            "AND NOT EXISTS (SELECT 1 FROM dmo_orders o WHERE o.request_id=q.request_id) " .
            "AND NOT EXISTS (SELECT 1 FROM dmo_quotes a WHERE a.request_id=q.request_id AND a.status='accepted')");
    } elseif ($kind==='order') {
        $event=$db->prepare("SELECT id FROM dmo_orders WHERE request_id=:id AND id=:event " .
            "AND status='awaiting_arrangement' AND payment_status='unverified'");
    } else return null;
    $event->execute(['id'=>$requestId,'event'=>$eventId]);
    return $event->fetchColumn()===false ? null : $row;
}

function dmo_notification_options(PDO $db,array $detail): array
{
    $options=[];
    $id=(int)$detail['request']['id'];
    foreach (['quote'=>$detail['quotes'],'order'=>$detail['orders']] as $kind=>$records) {
        foreach ($records as $row) {
            if (($kind==='quote' && $row['status']!=='sent') ||
                ($kind==='order' && $row['status']!=='awaiting_arrangement')) continue;
            $eventId=(int)$row['id'];
            $status=dmo_notification_status($db,$id,$kind,$eventId);
            $options[]=['kind'=>$kind,'event_id'=>$eventId,'status'=>$status,
                'can_send'=>$status==='not_sent' && dmo_notifications_enabled() &&
                    dmo_notification_target($db,$id,$kind,$eventId)!==null];
        }
    }
    return $options;
}

/**
 * Reserve under the same request lock used by quote/order mutations.
 * Reservation commits before SMTP: an interrupted/ambiguous attempt can never
 * automatically send twice. No recipient or body is stored in the audit log.
 */
function dmo_notification_reserve(PDO $db,int $adminId,int $requestId,string $kind,int $eventId): array
{
    $db->beginTransaction();
    try {
        $lock=$db->prepare('SELECT id FROM dmo_commission_requests WHERE id=:id FOR UPDATE');
        $lock->execute(['id'=>$requestId]);
        $target=$lock->fetchColumn()!==false ? dmo_notification_target($db,$requestId,$kind,$eventId) : null;
        if ($target===null) {
            $db->rollBack();
            return ['status'=>'ineligible'];
        }
        if (dmo_notification_status($db,$requestId,$kind,$eventId)!=='not_sent') {
            $db->rollBack();
            return ['status'=>'duplicate'];
        }
        dmo_workflow_audit($db,$adminId,$requestId,'notification.reserved',
            ['kind'=>$kind,'event_id'=>$eventId]);
        $db->commit();
        return ['status'=>'reserved','recipient'=>$target['email']];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function dmo_notification_finish(PDO $db,int $adminId,int $requestId,string $kind,int $eventId,bool $accepted): void
{
    dmo_workflow_audit($db,$adminId,$requestId,$accepted?'notification.accepted':'notification.failed',
        ['kind'=>$kind,'event_id'=>$eventId]);
}

function dmo_notification_send(PDO $db,array $admin,int $requestId,array $input): never
{
    if (count($input)!==5 || array_diff(array_keys($input),
        ['action','request_id','kind','event_id','confirm_send'])!==[] ||
        !in_array($input['kind']??null,['quote','order'],true) ||
        ($input['confirm_send']??null)!==true) {
        dmo_json(422,['ok'=>false,'error'=>'invalid_notification_request']);
    }
    if (!dmo_notifications_enabled()) dmo_json(503,['ok'=>false,'error'=>'customer_notifications_disabled']);
    $eventId=dmo_workflow_id($input['event_id']??null);
    $kind=$input['kind'];
    $adminId=(int)$admin['user_id'];
    if (!dmo_auth_rate_limit($db,'customer-notice',(string)$adminId,8,60)) {
        dmo_json(429,['ok'=>false,'error'=>'notification_rate_limit']);
    }
    $reservation=dmo_notification_reserve($db,$adminId,$requestId,$kind,$eventId);
    if ($reservation['status']!=='reserved') dmo_json(409,['ok'=>false,'error'=>'notification_'.$reservation['status']]);
    [$subject,$message]=dmo_notification_template($kind);
    $body=$message."\n\nSign in by visiting https://drawmeone.com/commission.html\n\n".
        "No payment is requested by this email. Please review the latest status in your account.\n";
    $accepted=dmo_mail_send($reservation['recipient'],$subject,$body);
    dmo_notification_finish($db,$adminId,$requestId,$kind,$eventId,$accepted);
    dmo_json($accepted?202:502,['ok'=>$accepted,'transport_accepted'=>$accepted,
        'status'=>$accepted?'accepted':'failed','delivery_confirmed'=>false]);
}
