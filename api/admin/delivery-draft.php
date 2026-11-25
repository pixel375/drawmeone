<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/deliveries.php';

header('Cache-Control: private, no-store');
final class DmoDeliveryRejected extends RuntimeException {
    public function __construct(public int $status,public string $reason){parent::__construct('Delivery draft rejected');}
}
try {
    $db=dmo_database();
    $admin=dmo_admin_require_session($db);
    $method=$_SERVER['REQUEST_METHOD']??'';
    if($method==='GET'){
        if(!dmo_delivery_schema_ready($db))dmo_json(404,['ok'=>false,'error'=>'draft_not_found']);
        $id=dmo_workflow_id($_GET['id']??null);
        $q=$db->prepare('SELECT storage_key,sha256,byte_size FROM dmo_delivery_drafts WHERE id=:id LIMIT 1');
        $q->execute(['id'=>$id]);$row=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($row))dmo_json(404,['ok'=>false,'error'=>'draft_not_found']);
        $path=dmo_delivery_file($row['storage_key']);
        if(!is_file($path)||!is_readable($path)||filesize($path)!==(int)$row['byte_size']||
            !hash_equals($row['sha256'],(string)hash_file('sha256',$path)))dmo_json(404,['ok'=>false,'error'=>'draft_not_found']);
        header('Content-Type: image/webp');
        header('X-Robots-Tag: noindex, nofollow');
        header('Content-Length: '.filesize($path));
        $view=$_GET['view']??'download';
        if(!is_string($view)||!in_array($view,['download','preview'],true))dmo_json(422,['ok'=>false,'error'=>'invalid_view']);
        header('Content-Disposition: '.($view==='preview'?'inline':'attachment').'; filename="commission-draft-'.$id.'.webp"');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cross-Origin-Resource-Policy: same-origin');
        header("Content-Security-Policy: default-src 'none'");
        header('X-Content-Type-Options: nosniff');
        readfile($path);exit;
    }
    if($method!=='POST'){header('Allow: GET, POST');dmo_json(405,['ok'=>false,'error'=>'method_not_allowed']);}
    if(!dmo_delivery_staging_enabled($db))dmo_json(503,['ok'=>false,'error'=>'delivery_staging_disabled']);
    if(!dmo_auth_https()||($_SERVER['HTTP_ORIGIN']??'')!==DMO_AUTH_ORIGIN||
        !str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'multipart/form-data'))
        dmo_json(403,['ok'=>false,'error'=>'secure_upload_required']);
    dmo_auth_verify_csrf($admin);
    if(count($_POST)!==2||count($_FILES)!==1)dmo_json(422,['ok'=>false,'error'=>'invalid_upload_fields']);
    $requestId=dmo_workflow_id($_POST['request_id']??null);
    $orderId=dmo_workflow_id($_POST['order_id']??null);
    if(!dmo_delivery_order_eligible($db,$requestId,$orderId))dmo_json(409,['ok'=>false,'error'=>'order_not_eligible']);
    $file=$_FILES['artwork']??null;
    if((int)($_SERVER['CONTENT_LENGTH']??0)>DMO_ARTWORK_MAX_BYTES+1048576||
        !is_array($file)||!is_string($file['tmp_name']??null)||($file['error']??null)!==UPLOAD_ERR_OK||
        !is_int($file['size']??null)||$file['size']<1||$file['size']>DMO_ARTWORK_MAX_BYTES)
        dmo_json(422,['ok'=>false,'error'=>'invalid_upload']);
    if(!dmo_auth_rate_limit($db,'delivery-stage',(string)$admin['user_id'],12,60))
        dmo_json(429,['ok'=>false,'error'=>'too_many_uploads']);
    [$source,$w,$h]=dmo_artwork_open_image($file['tmp_name']);
    $folder='';$committed=false;
    try{
        $root=dmo_delivery_storage_root();
        if(!is_dir($root)&&!mkdir($root,0700,true)&&!is_dir($root))throw new RuntimeException('Private delivery root unavailable');
        $key=bin2hex(random_bytes(16));$folder=$root.'/'.$key;
        if(!mkdir($folder,0700))throw new RuntimeException('Private delivery folder unavailable');
        $path=$folder.'/display.webp';
        $meta=dmo_artwork_make_webp($source,$w,$h,$path,max($w,$h),95);
        if(!chmod($path,0600))throw new RuntimeException('Private file permissions failed');
        $digest=hash_file('sha256',$path);
        if(!is_string($digest))throw new RuntimeException('Draft checksum unavailable');
        $db->beginTransaction();
        $lock=$db->prepare('SELECT id FROM dmo_commission_requests WHERE id=:id FOR UPDATE');
        $lock->execute(['id'=>$requestId]);
        if($lock->fetchColumn()===false||!dmo_delivery_order_eligible($db,$requestId,$orderId))
            throw new DmoDeliveryRejected(409,'order_not_eligible');
        $count=$db->prepare('SELECT COUNT(*) FROM dmo_delivery_drafts WHERE order_id=:id');
        $count->execute(['id'=>$orderId]);
        if((int)$count->fetchColumn()>=DMO_DELIVERY_DRAFT_LIMIT)throw new DmoDeliveryRejected(409,'draft_limit_reached');
        $db->prepare('INSERT INTO dmo_delivery_drafts (request_id,order_id,uploaded_by,storage_key,pixel_width,pixel_height,byte_size,sha256) ' .
            'VALUES (:request,:order,:actor,:key,:w,:h,:bytes,:sha)')->execute([
                'request'=>$requestId,'order'=>$orderId,'actor'=>(int)$admin['user_id'],'key'=>$key.'/display.webp',
                'w'=>$meta['width'],'h'=>$meta['height'],'bytes'=>$meta['bytes'],'sha'=>$digest]);
        $id=(int)$db->lastInsertId();
        dmo_workflow_audit($db,(int)$admin['user_id'],$requestId,'delivery.draft_staged',['draft_id'=>$id,'order_id'=>$orderId]);
        $db->commit();$committed=true;
    }finally{
        imagedestroy($source);
        if(!$committed){
            if($db->inTransaction())$db->rollBack();
            if($folder!==''){@unlink($folder.'/display.webp');@rmdir($folder);}
        }
    }
    dmo_json(201,['ok'=>true,'draft_id'=>$id,'customer_visible'=>false]);
}catch(DmoDeliveryRejected $error){
    dmo_json($error->status,['ok'=>false,'error'=>$error->reason]);
}catch(Throwable $error){
    dmo_log_problem($error,'private delivery draft');
    dmo_json(503,['ok'=>false,'error'=>'delivery_draft_unavailable']);
}
