<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/checkout.php';
dmo_reject_non_get();
try{
    $db=dmo_database();$session=dmo_customer_require($db);
    if(!dmo_checkout_enabled($db))dmo_json(404,['ok'=>false,'error'=>'checkout_not_available']);
    $id=dmo_workflow_id($_GET['id']??null);
    $q=$db->prepare("SELECT l.id,l.order_id,l.url_ciphertext,l.url_fingerprint,l.provider_host,l.currency,l.amount_minor,r.id AS request_id
       FROM dmo_checkout_links l JOIN dmo_orders o ON o.id=l.order_id JOIN dmo_commission_requests r ON r.id=o.request_id
       WHERE l.id=:id AND r.customer_id=:customer AND l.status='active' AND l.expires_at>UTC_TIMESTAMP()
       AND l.id=(SELECT MAX(x.id) FROM dmo_checkout_links x WHERE x.order_id=o.id)");
    $q->execute(['id'=>$id,'customer'=>$session['user_id']]);$row=$q->fetch(PDO::FETCH_ASSOC);
    $eligible=is_array($row)?dmo_checkout_eligible($db,(int)$row['request_id'],(int)$row['order_id']):null;
    if($eligible===null||$row['currency']!==$eligible['currency']||(int)$row['amount_minor']!==(int)$eligible['total_minor']||
        $row['provider_host']!==(dmo_checkout_config()['host']??null))dmo_json(404,['ok'=>false,'error'=>'checkout_not_available']);
    $url=dmo_checkout_authenticated_url($row);
    if($url===null||!dmo_checkout_url($url))dmo_json(404,['ok'=>false,'error'=>'checkout_not_available']);
    dmo_api_headers();header('Location: '.$url,true,303);exit;
}catch(Throwable $e){dmo_log_problem($e,'customer checkout');dmo_json(503,['ok'=>false,'error'=>'checkout_unavailable']);}
