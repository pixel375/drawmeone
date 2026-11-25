<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/app/artwork-reviews.php';
header('Cache-Control: private, no-store');
try{
    $db=dmo_database();$session=dmo_customer_require($db);
    if(!dmo_review_enabled($db))dmo_json(404,['ok'=>false,'error'=>'review_not_available']);
    if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
        $input=dmo_auth_json_request();dmo_auth_verify_csrf($session);
        dmo_review_respond($db,$session,$input);
    }
    dmo_reject_non_get();
    $id=dmo_workflow_id($_GET['id']??null);
    $q=$db->prepare('SELECT d.storage_key,d.byte_size,d.sha256,r.id AS request_id,r.policy_version'.dmo_review_owner_query().' AND v.id=:id LIMIT 1');
    $q->execute(['customer'=>(int)$session['user_id'],'id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
    if(!is_array($row)||!dmo_policy_request_eligible($db,(int)$row['request_id'],$row['policy_version']))dmo_json(404,['ok'=>false,'error'=>'review_not_available']);
    $path=dmo_delivery_file($row['storage_key']);
    if(!is_file($path)||filesize($path)!==(int)$row['byte_size']||!hash_equals($row['sha256'],(string)hash_file('sha256',$path)))
        dmo_json(404,['ok'=>false,'error'=>'review_not_available']);
    $source=@imagecreatefromwebp($path);
    if(!$source instanceof GdImage)throw new RuntimeException('Cannot decode proof');
    $w=imagesx($source);$h=imagesy($source);$scale=min(1,720/max($w,$h));
    $proof=imagecreatetruecolor(max(1,(int)round($w*$scale)),max(1,(int)round($h*$scale)));
    if(!$proof instanceof GdImage){imagedestroy($source);throw new RuntimeException('Cannot allocate proof');}
    imagefill($proof,0,0,imagecolorallocate($proof,255,255,255));
    imagecopyresampled($proof,$source,0,0,0,0,imagesx($proof),imagesy($proof),$w,$h);imagedestroy($source);
    $ink=imagecolorallocatealpha($proof,0,0,0,42);$light=imagecolorallocatealpha($proof,255,255,255,25);
    for($y=8;$y<imagesy($proof);$y+=65)for($x=6;$x<imagesx($proof);$x+=130){
        imagestring($proof,4,$x+1,$y+1,'DRAWMEONE PROOF',$ink);
        imagestring($proof,4,$x,$y,'DRAWMEONE PROOF',$light);
    }
    header('Content-Type: image/webp');header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');header('X-Frame-Options: DENY');header("Content-Security-Policy: default-src 'none'");
    header('Cross-Origin-Resource-Policy: same-origin');header('Referrer-Policy: no-referrer');
    imagewebp($proof,null,72);imagedestroy($proof);exit;
}catch(Throwable $e){dmo_log_problem($e,'customer artwork review');dmo_json(503,['ok'=>false,'error'=>'review_unavailable']);}
