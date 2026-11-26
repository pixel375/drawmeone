<?php
declare(strict_types=1);
require_once __DIR__.'/quotes.php';
require_once __DIR__.'/deliveries.php';
require_once __DIR__.'/checkout.php';

/** Read-only shortcuts across the customer's history. Never expose file, payment
 * or internal record identifiers. Detail endpoints remain authoritative. */
function dmo_customer_attention(PDO $db,int $customer): array
{
    $result=['quotes'=>[],'proofs'=>[],'deliveries'=>[],'invoices'=>[],'clarifications'=>[],'artist_replies'=>[]];
    $q=$db->prepare("SELECT public_ref FROM dmo_commission_requests WHERE customer_id=:customer AND status='clarification_needed' ORDER BY updated_at DESC,id DESC LIMIT 10");
    $q->execute(['customer'=>$customer]);$result['clarifications']=$q->fetchAll(PDO::FETCH_COLUMN);
    // This is a recent-reply shortcut, not an unread or response-required flag.
    // Private notes never affect the latest customer-visible message.
    $q=$db->prepare("SELECT r.public_ref FROM dmo_commission_requests r
        JOIN dmo_conversations c ON c.request_id=r.id JOIN dmo_messages m ON m.conversation_id=c.id
        JOIN dmo_users u ON u.id=m.sender_id
        WHERE r.customer_id=:customer AND r.status NOT IN ('cancelled','canceled','rejected','declined','completed','closed','archived','refunded')
        AND m.is_internal=0 AND u.role='admin' AND m.created_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 14 DAY)
        AND NOT EXISTS(SELECT 1 FROM dmo_messages later WHERE later.conversation_id=c.id AND later.is_internal=0 AND later.id>m.id)
        ORDER BY m.id DESC LIMIT 10");
    $q->execute(['customer'=>$customer]);$result['artist_replies']=$q->fetchAll(PDO::FETCH_COLUMN);
    if(dmo_quote_response_enabled()){
        $q=$db->prepare("SELECT r.id,r.public_ref,r.policy_version FROM dmo_commission_requests r
          JOIN dmo_quotes q ON q.request_id=r.id WHERE r.customer_id=:customer
          AND r.status IN ('submitted','clarification_needed') AND q.status='sent'
          AND q.sent_at IS NOT NULL AND q.validity_ends_at>UTC_TIMESTAMP()
          AND NOT EXISTS(SELECT 1 FROM dmo_quotes a WHERE a.request_id=r.id AND a.status='accepted')
          AND NOT EXISTS(SELECT 1 FROM dmo_orders o WHERE o.request_id=r.id)
          ORDER BY q.validity_ends_at ASC,q.id DESC LIMIT 100");
        $q->execute(['customer'=>$customer]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            if(dmo_policy_request_eligible($db,(int)$row['id'],(string)$row['policy_version']))
                $result['quotes'][]=$row['public_ref'];
            if(count($result['quotes'])>=10)break;
        }
    }
    if(dmo_review_enabled($db)){
        $q=$db->prepare('SELECT r.id,r.public_ref,r.policy_version'.dmo_review_owner_query().' ORDER BY v.id DESC LIMIT 100');
        $q->execute(['customer'=>$customer]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            if(!in_array($row['public_ref'],$result['proofs'],true)&&
                dmo_policy_request_eligible($db,(int)$row['id'],(string)$row['policy_version']))
                $result['proofs'][]=$row['public_ref'];
            if(count($result['proofs'])>=10)break;
        }
    }
    if(dmo_delivery_release_ready($db)){
        $q=$db->prepare('SELECT r.public_ref'.dmo_delivery_customer_query().' ORDER BY f.id DESC LIMIT 10');
        $q->execute(['customer'=>$customer]);
        $result['deliveries']=$q->fetchAll(PDO::FETCH_COLUMN);
    }
    if(dmo_checkout_enabled($db)){
        $q=$db->prepare("SELECT DISTINCT r.id,r.public_ref FROM dmo_commission_requests r
          JOIN dmo_orders o ON o.request_id=r.id JOIN dmo_checkout_links l ON l.order_id=o.id
          WHERE r.customer_id=:customer AND o.status='awaiting_arrangement' AND l.status='active'
          ORDER BY r.id DESC LIMIT 100");
        $q->execute(['customer'=>$customer]);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
            if(dmo_checkout_rows($db,(int)$row['id']))$result['invoices'][]=$row['public_ref'];
            if(count($result['invoices'])>=10)break;
        }
    }
    foreach($result as &$refs)$refs=array_values(array_unique($refs));
    unset($refs);
    return $result;
}
