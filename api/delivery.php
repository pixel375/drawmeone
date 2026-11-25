<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/deliveries.php';
header('Cache-Control: private, no-store');
dmo_reject_non_get();
try{
    $db=dmo_database();
    $customer=dmo_customer_require($db);
    if(!dmo_delivery_release_ready($db))dmo_json(404,['ok'=>false,'error'=>'delivery_not_found']);
    $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!is_int($id))dmo_json(404,['ok'=>false,'error'=>'delivery_not_found']);
    $q=$db->prepare('SELECT f.storage_key,f.byte_size,d.sha256,d.byte_size AS draft_bytes'.
        dmo_delivery_customer_query().' AND f.id=:id LIMIT 1');
    $q->execute(['customer'=>(int)$customer['user_id'],'id'=>$id]);
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row))dmo_json(404,['ok'=>false,'error'=>'delivery_not_found']);
    $path=dmo_delivery_file($row['storage_key']);
    if(!is_file($path)||!is_readable($path)||filesize($path)!==(int)$row['byte_size']||
        (int)$row['byte_size']!==(int)$row['draft_bytes']||
        !hash_equals($row['sha256'],(string)hash_file('sha256',$path)))
        dmo_json(404,['ok'=>false,'error'=>'delivery_not_found']);
    header('Content-Type: image/webp');
    header('X-Robots-Tag: noindex, nofollow');
    header('Content-Length: '.filesize($path));
    header('Content-Disposition: attachment; filename="commission-artwork-'.$id.'.webp"');
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'none'");
    readfile($path);exit;
}catch(Throwable $error){
    dmo_log_problem($error,'customer artwork download');
    dmo_json(503,['ok'=>false,'error'=>'delivery_unavailable']);
}
