<?php
declare(strict_types=1);
require_once __DIR__.'/orders.php';
require_once __DIR__.'/deliveries.php';

/** Fixed, internal SQL predicates shared by the counters and work queues. */
function dmo_artist_reply_due_sql(): string
{
    return "r.status IN ('submitted','clarification_needed') AND EXISTS(
        SELECT 1 FROM dmo_conversations c JOIN dmo_messages m ON m.conversation_id=c.id
        JOIN dmo_users sender ON sender.id=m.sender_id
        WHERE c.request_id=r.id AND m.is_internal=0 AND sender.role='customer'
        AND NOT EXISTS(SELECT 1 FROM dmo_messages later WHERE later.conversation_id=c.id AND later.is_internal=0 AND later.id>m.id))";
}
function dmo_artist_active_order_sql(): string
{
    return "o.status IN ('awaiting_arrangement','in_progress','ready_for_delivery','queued','drawing','awaiting_feedback','revision')";
}
function dmo_artist_queue_conditions(PDO $db): array
{
    $active=dmo_artist_active_order_sql();
    $conditions=[
        'reply_due'=>dmo_artist_reply_due_sql(),
        'accepted'=>"o.id IS NULL AND vq.status='accepted' AND r.status IN ('submitted','clarification_needed')",
        'overdue'=>$active." AND o.agreed_deadline IS NOT NULL AND DATE(o.agreed_deadline)<UTC_DATE()",
        'upcoming'=>$active." AND o.agreed_deadline IS NOT NULL AND DATE(o.agreed_deadline) BETWEEN UTC_DATE() AND DATE_ADD(UTC_DATE(),INTERVAL 7 DAY)",
        'revision_due'=>'1=0','proof_waiting'=>'1=0',
    ];
    if(dmo_review_schema_ready($db)){
        foreach(['revision_due'=>'revision_requested','proof_waiting'=>'pending'] as $key=>$status){
            $conditions[$key]="r.status IN ('submitted','clarification_needed') AND o.status='in_progress' AND ".
                "(SELECT v.status FROM dmo_artwork_reviews v WHERE v.order_id=o.id ORDER BY v.round_number DESC,v.id DESC LIMIT 1)='".$status."'";
        }
    }
    return $conditions;
}
