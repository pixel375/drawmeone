<?php
declare(strict_types=1);

require_once __DIR__ . '/admin.php';

const DMO_ARTWORK_MAX_BYTES = 8 * 1024 * 1024;
const DMO_ARTWORK_MAX_PIXELS = 16000000;

/** Database-scoped, bounded upload lock; released when nonpersistent PDO closes. */
function dmo_artwork_upload_lock_name(PDO $db): string
{
    return 'dmo-artwork-upload-'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,40);
}
/** Read-only capability check, not proof of a successful disk write or image upload. */
function dmo_artwork_runtime(): array
{
    $required=['imagecreatefromjpeg','imagecreatefrompng','imagecreatefromwebp','imagewebp','imagecreatetruecolor','imagecopyresampled','imageflip','imagerotate','getimagesize','hash_file'];
    $missing=array_values(array_filter($required,static fn($name)=>!function_exists($name)));
    return ['ready'=>extension_loaded('gd')&&class_exists('finfo')&&$missing===[],
        'jpeg_orientation'=>function_exists('exif_read_data'),'missing_function_count'=>count($missing)];
}

function dmo_artwork_writes_enabled(): bool
{
    return dmo_config('DMO_ARTWORK_WRITES_ENABLED') === '1';
}

function dmo_artwork_id(mixed $value): int
{
    if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value)) {
        $value = (int) $value;
    }
    if (!is_int($value) || $value < 1) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_artwork_id']);
    }
    return $value;
}

function dmo_artwork_storage_root(): string
{
    $env = dmo_private_env_path();
    if ($env === null) {
        throw new RuntimeException('Private configuration path is unavailable.');
    }
    $root = dirname($env) . '/drawmeone-artworks';
    $webroot = realpath(dirname(__DIR__));
    // Defence in depth: never write upload data beneath public_html.
    $parent = realpath(dirname($root));
    if ($webroot === false || $parent === false ||
        $parent === $webroot ||
        str_starts_with($parent . '/', $webroot . '/')) {
        throw new RuntimeException('Unsafe private artwork root.');
    }
    return $root;
}

function dmo_artwork_storage_file(string $key): string
{
    if (!preg_match('/^[a-f0-9]{32}\/(thumb|display)\.webp$/D', $key)) {
        throw new InvalidArgumentException('Unsafe artwork storage key.');
    }
    return dmo_artwork_storage_root() . '/' . $key;
}

/** Normalize JPEG camera orientation before the metadata-free WebP conversion. */
function dmo_artwork_orient(GdImage $source,int $orientation): GdImage
{
    if(in_array($orientation,[2,5,7],true)&&!imageflip($source,IMG_FLIP_HORIZONTAL))
        throw new RuntimeException('Image orientation flip failed.');
    if($orientation===4&&!imageflip($source,IMG_FLIP_VERTICAL))
        throw new RuntimeException('Image orientation flip failed.');
    // GD angles are counterclockwise. Mirror first for orientations 5 and 7.
    $angle=match($orientation){3=>180,5,8=>90,6,7=>-90,default=>0};
    if($angle===0)return $source;
    $rotated=imagerotate($source,$angle,0);
    if(!$rotated instanceof GdImage)throw new RuntimeException('Image orientation rotation failed.');
    return $rotated;
}
function dmo_artwork_open_image(string $tmp): array
{
    if (!is_uploaded_file($tmp) || !extension_loaded('gd') ||
        !function_exists('imagewebp')) {
        dmo_json(503, ['ok' => false, 'error' => 'image_processing_unavailable']);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    if (!is_string($mime) ||
        !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        dmo_json(422, ['ok' => false, 'error' => 'unsupported_image_format']);
    }
    $size = @getimagesize($tmp);
    if (!is_array($size) || ($size['mime'] ?? '') !== $mime) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_image_data']);
    }
    $width = (int) ($size[0] ?? 0);
    $height = (int) ($size[1] ?? 0);
    if ($width < 100 || $height < 100 ||
        $width > 6500 || $height > 6500 ||
        $width * $height > DMO_ARTWORK_MAX_PIXELS) {
        dmo_json(422, ['ok' => false, 'error' => 'image_dimensions_out_of_range']);
    }
    $source = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png' => @imagecreatefrompng($tmp),
        'image/webp' => @imagecreatefromwebp($tmp),
    };
    if (!$source instanceof GdImage) {
        dmo_json(422, ['ok' => false, 'error' => 'image_decode_failed']);
    }
    if($mime==='image/jpeg'&&function_exists('exif_read_data')){
        $metadata=@exif_read_data($tmp,'IFD0',true,false);
        $orientation=is_array($metadata)?(int)($metadata['IFD0']['Orientation']??1):1;
        if($orientation>=2&&$orientation<=8){
            $source=dmo_artwork_orient($source,$orientation);
            $width=imagesx($source);$height=imagesy($source);
        }
    }
    return [$source, $width, $height];
}

function dmo_artwork_make_webp(
    GdImage $source, int $width, int $height, string $path, int $longest, int $quality
): array {
    $scale = min(1.0, $longest / max($width, $height));
    $w = max(1, (int) round($width * $scale));
    $h = max(1, (int) round($height * $scale));
    $canvas = imagecreatetruecolor($w, $h);
    if (!$canvas instanceof GdImage) {
        throw new RuntimeException('Unable to allocate image canvas.');
    }
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $w - 1, $h - 1, $transparent);
    $copied = imagecopyresampled(
        $canvas, $source, 0, 0, 0, 0, $w, $h, $width, $height
    );
    $written = $copied && imagewebp($canvas, $path, $quality);
    imagedestroy($canvas);
    if (!$written || !is_file($path) || !chmod($path,0600) || filesize($path) < 100) {
        throw new RuntimeException('Unable to write optimized image.');
    }
    return ['width' => $w, 'height' => $h, 'bytes' => filesize($path)];
}

/** Optional multipart operation key, scoped by the authenticated uploader. */
function dmo_upload_operation_key(array $fields): ?string
{
    if(!array_key_exists('operation_key',$fields))return null;
    $key=$fields['operation_key'];
    if(!is_string($key)||!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$key))
        dmo_json(422,['ok'=>false,'error'=>'invalid_operation_key']);
    return $key;
}
function dmo_upload_payload_digest(string $tmp,string $domain): string
{
    $hash=hash_file('sha256',$tmp);
    if(!is_string($hash))throw new RuntimeException('Upload digest unavailable.');
    return hash_hmac('sha256',$domain.'|'.$hash,dmo_config('DMO_APP_KEY'));
}
function dmo_artwork_upload_replay(PDO $db,int $admin,?string $key,?string $digest): ?array
{
    if($key===null)return null;
    $q=$db->prepare("SELECT a.entity_id, a.details_json, p.visibility FROM dmo_audit_log a
        LEFT JOIN dmo_portfolio_artworks p ON p.id=a.entity_id
        WHERE a.actor_id=:actor AND a.entity_type='portfolio_artwork' AND a.action='artwork.uploaded_as_draft'
        AND JSON_UNQUOTE(JSON_EXTRACT(a.details_json,'$.operation_key'))=:operation ORDER BY a.id DESC LIMIT 1");
    $q->execute(['actor'=>$admin,'operation'=>$key]);$row=$q->fetch();
    if(!is_array($row))return null;
    $detail=json_decode((string)$row['details_json'],true);
    if(!is_string($detail['payload_digest']??null)||!hash_equals($detail['payload_digest'],(string)$digest))
        dmo_json(409,['ok'=>false,'error'=>'operation_key_conflict']);
    if(!in_array($row['visibility'],['draft','published'],true))
        dmo_json(409,['ok'=>false,'error'=>'artwork_receipt_unavailable']);
    return ['ok'=>true,'artwork_id'=>(int)$row['entity_id'],'visibility'=>$row['visibility'],
        'width'=>(int)$detail['width'],'height'=>(int)$detail['height'],'already_saved'=>true];
}

/** Optional exact original-byte match. No visual similarity claim and no mutation. */
function dmo_artwork_identical(PDO $db,string $digest): ?array
{
    $q=$db->prepare("SELECT p.id,p.visibility FROM dmo_audit_log a JOIN dmo_portfolio_artworks p ON p.id=a.entity_id
        WHERE a.entity_type='portfolio_artwork' AND a.action='artwork.uploaded_as_draft'
        AND JSON_UNQUOTE(JSON_EXTRACT(a.details_json,'$.payload_digest'))=:digest
        ORDER BY a.id ASC LIMIT 1");
    $q->execute(['digest'=>$digest]);$row=$q->fetch();
    if(!is_array($row))return null;
    if(!dmo_artwork_publishable($db,(int)$row['id']))dmo_json(409,['ok'=>false,'error'=>'identical_artwork_needs_review','artwork_id'=>(int)$row['id']]);
    return ['ok'=>true,'artwork_id'=>(int)$row['id'],'visibility'=>$row['visibility'],'identical_skipped'=>true];
}
function dmo_artwork_record_audit(PDO $db, int $adminId, int $artworkId, string $action,?array $details=null): void
{
    $stmt = $db->prepare(
        "INSERT INTO dmo_audit_log (actor_id, action, entity_type, entity_id,details_json) " .
        "VALUES (:admin, :action, 'portfolio_artwork', :artwork,:details)"
    );
    $stmt->execute([
        'admin' => $adminId, 'action' => $action, 'artwork' => $artworkId,
        'details'=>$details===null?null:json_encode($details,JSON_THROW_ON_ERROR),
    ]);
}

function dmo_artwork_send_file(PDO $db, int $id, string $variant, bool $admin): never
{
    // Errors and private previews must not be retained by caches.
    header('Cache-Control: private, no-store');
    if (!in_array($variant, ['thumb', 'display'], true)) {
        http_response_code(404);
        exit;
    }
    $stmt = $db->prepare(
        'SELECT f.storage_key,f.byte_size,f.mime_type,f.pixel_width,f.pixel_height,f.variant FROM dmo_artwork_files f ' .
        'JOIN dmo_portfolio_artworks a ON a.id = f.artwork_id ' .
        'WHERE a.id = :id AND f.variant = :variant ' .
        ($admin ? '' : "AND a.visibility = 'published' ") .
        'LIMIT 1'
    );
    $stmt->execute(['id' => $id, 'variant' => $variant]);
    $found = $stmt->fetch();
    if (!is_array($found)) {
        http_response_code(404);
        exit;
    }
    $path = dmo_artwork_storage_file((string) $found['storage_key']);
    if (!dmo_artwork_variant_valid($found)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/webp');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Content-Security-Policy: default-src ' . "'none'");
    if (!$admin) {
        // Revalidate every public image request against current visibility.
        // Only emit a 304 after the published row and readable file are verified.
        $digest = hash_file('sha256', $path);
        header('Cache-Control: public, no-cache, must-revalidate');
        if (is_string($digest)) {
            $etag = '"' . $digest . '"';
            header('ETag: ' . $etag);
            $requestTags = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            if (is_string($requestTags)) {
                foreach (explode(',', $requestTags) as $tag) {
                    $tag = trim($tag);
                    if (str_starts_with($tag, 'W/')) $tag = substr($tag, 2);
                    if ($tag === '*' || $tag === $etag) {
                        http_response_code(304);
                        exit;
                    }
                }
            }
        }
    }
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/** All image stores must remain private at the directory boundary.
 * Legacy owner-readable files may have read bits outside the owner for
 * portfolio only; no group/world write, symlinks or exposed directories. */
function dmo_private_image_path_ok(string $path,bool $strictFile=false): bool
{
    clearstatcache(true);
    foreach([$path,dirname($path),dirname(dirname($path))] as $part){
        if(is_link($part))return false;
        $mode=@fileperms($part);
        if(!is_int($mode)||($mode&($part===$path&&!$strictFile?0022:0077))!==0)return false;
    }
    return is_file($path)&&is_readable($path)&&is_dir(dirname($path))&&is_dir(dirname(dirname($path)));
}

/** Validate an optimized file against recorded metadata before publication. */
function dmo_artwork_variant_valid(array $row): bool
{
    try{
        $path=dmo_artwork_storage_file((string)$row['storage_key']);
        clearstatcache(true,$path);
        if(isset($row['variant'])&&(!in_array($row['variant'],['thumb','display'],true)||!str_ends_with($path,'/'.$row['variant'].'.webp')))return false;
        if(!dmo_private_image_path_ok($path)||$row['mime_type']!=='image/webp'||
            filesize($path)!==(int)$row['byte_size'])return false;
        $size=@getimagesize($path);
        return is_array($size)&&($size['mime']??null)==='image/webp'&&
            (int)$size[0]===(int)$row['pixel_width']&&(int)$size[1]===(int)$row['pixel_height'];
    }catch(Throwable $e){return false;}
}

function dmo_artwork_publishable(PDO $db,int $id): bool
{
    $q=$db->prepare('SELECT variant,storage_key,mime_type,byte_size,pixel_width,pixel_height FROM dmo_artwork_files WHERE artwork_id=:id');
    $q->execute(['id'=>$id]);$found=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $file){
        if(in_array($file['variant'],['thumb','display'],true)&&dmo_artwork_variant_valid($file))$found[$file['variant']]=true;
    }
    return isset($found['thumb'],$found['display']);
}
/** Bounded atomic bulk visibility changes. All rows locked in ascending ID order. */
function dmo_artwork_batch_visibility(PDO $db,int $admin,array $input): never
{
    $ids=$input['ids']??null;$target=$input['visibility']??null;
    if(count($input)!==4||array_diff(array_keys($input),['action','ids','visibility','confirm'])||
        ($input['confirm']??null)!==true||!in_array($target,['draft','published'],true)||
        !is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>60)
        dmo_json(422,['ok'=>false,'error'=>'invalid_artwork_batch']);
    foreach($ids as $id)if(!is_int($id)||$id<1)dmo_json(422,['ok'=>false,'error'=>'invalid_artwork_batch']);
    if(count(array_unique($ids))!==count($ids))dmo_json(422,['ok'=>false,'error'=>'invalid_artwork_batch']);
    sort($ids,SORT_NUMERIC);
    $expected=$target==='published'?'draft':'published';
    $db->beginTransaction();
    try{
        $lock=$db->prepare('SELECT visibility FROM dmo_portfolio_artworks WHERE id=:id FOR UPDATE');
        foreach($ids as $id){
            $lock->execute(['id'=>$id]);
            if($lock->fetchColumn()!==$expected){
                $db->rollBack();dmo_json(409,['ok'=>false,'error'=>'artwork_batch_changed']);
            }
            if($target==='published'&&!dmo_artwork_publishable($db,$id)){
                $db->rollBack();dmo_json(422,['ok'=>false,'error'=>'optimized_artwork_missing']);
            }
        }
        $update=$db->prepare('UPDATE dmo_portfolio_artworks SET visibility=:visibility,published_at='.
            ($target==='published'?'COALESCE(published_at,UTC_TIMESTAMP())':'NULL').' WHERE id=:id');
        foreach($ids as $id){
            $update->execute(['visibility'=>$target,'id'=>$id]);
            dmo_artwork_record_audit($db,$admin,$id,'artwork.set_visibility');
        }
        $db->commit();
        dmo_json(200,['ok'=>true,'updated'=>count($ids),'visibility'=>$target]);
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}

/** Swap adjacent published drawings within one featured group. Rebalance that
 * group only when equal sort values prevent a two-row swap. */
function dmo_artwork_move(PDO $db,int $admin,array $input): never
{
    if(array_diff(array_keys($input),['action','id','neighbor_id','direction','expected_order','expected_neighbor_order','expected_featured'])!==[]||
        count($input)!==7||!in_array($input['direction']??null,['earlier','later'],true)||
        !is_bool($input['expected_featured']??null)||!is_int($input['expected_order']??null)||!is_int($input['expected_neighbor_order']??null))
        dmo_json(422,['ok'=>false,'error'=>'invalid_artwork_move']);
    $id=dmo_artwork_id($input['id']??null);$neighbor=dmo_artwork_id($input['neighbor_id']??null);
    if($id===$neighbor)dmo_json(422,['ok'=>false,'error'=>'invalid_artwork_move']);
    $db->beginTransaction();
    try{
        // Lock in primary-key order, then sort the locked snapshot in PHP.
        $rows=$db->query("SELECT id,sort_order,is_featured FROM dmo_portfolio_artworks WHERE visibility='published' ORDER BY id ASC FOR UPDATE")->fetchAll();
        $group=array_values(array_filter($rows,static fn($r)=>(bool)$r['is_featured']===$input['expected_featured']));
        usort($group,static fn($a,$b)=>(int)$a['sort_order']<=>(int)$b['sort_order']?:((int)$b['id']<=>(int)$a['id']));
        $ids=array_map('intval',array_column($group,'id'));$index=array_search($id,$ids,true);
        $adjacent=$index===false?-1:$index+($input['direction']==='earlier'?-1:1);
        if($index===false||($ids[$adjacent]??null)!==$neighbor||
            (int)$group[$index]['sort_order']!==$input['expected_order']||(int)$group[$adjacent]['sort_order']!==$input['expected_neighbor_order']){
            $db->rollBack();dmo_json(409,['ok'=>false,'error'=>'artwork_move_changed']);
        }
        // Ties with any other row can prevent an exact swap, so reassign
        // sequential values only when the group's ordering is ambiguous.
        $orders=array_map('intval',array_column($group,'sort_order'));
        [$group[$index],$group[$adjacent]]=[$group[$adjacent],$group[$index]];
        $update=$db->prepare('UPDATE dmo_portfolio_artworks SET sort_order=:sort WHERE id=:id');
        if(count($group)>2000){$db->rollBack();dmo_json(409,['ok'=>false,'error'=>'artwork_library_limit']);}
        $rebalance=count(array_unique($orders))!==count($orders);
        $changed=0;
        foreach($group as $i=>$row){
            $new=$orders[$i];
            if($rebalance)$new=$i*5;
            if($new===(int)$row['sort_order'])continue;
            $update->execute(['sort'=>$new,'id'=>(int)$row['id']]);
            dmo_artwork_record_audit($db,$admin,(int)$row['id'],'artwork.set_order');
            $changed++;
        }
        $db->commit();dmo_json(200,['ok'=>true,'updated'=>$changed]);
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}

/** Feature a selected set without changing publication visibility. */
function dmo_artwork_batch_featured(PDO $db,int $admin,array $input): never
{
    $ids=$input['ids']??null;$target=$input['featured']??null;
    if(count($input)!==4||array_diff(array_keys($input),['action','ids','featured','confirm'])||
        ($input['confirm']??null)!==true||!is_bool($target)||!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>60)
        dmo_json(422,['ok'=>false,'error'=>'invalid_artwork_batch']);
    foreach($ids as $id)if(!is_int($id)||$id<1)dmo_json(422,['ok'=>false,'error'=>'invalid_artwork_batch']);
    if(count(array_unique($ids))!==count($ids))dmo_json(422,['ok'=>false,'error'=>'invalid_artwork_batch']);
    sort($ids,SORT_NUMERIC);$db->beginTransaction();
    try{
        $lock=$db->prepare('SELECT is_featured FROM dmo_portfolio_artworks WHERE id=:id FOR UPDATE');
        foreach($ids as $id){
            $lock->execute(['id'=>$id]);$current=$lock->fetchColumn();
            if($current===false||(bool)$current===$target){$db->rollBack();dmo_json(409,['ok'=>false,'error'=>'artwork_batch_changed']);}
        }
        $update=$db->prepare('UPDATE dmo_portfolio_artworks SET is_featured=:featured WHERE id=:id');
        foreach($ids as $id){
            $update->execute(['featured'=>(int)$target,'id'=>$id]);
            dmo_artwork_record_audit($db,$admin,$id,'artwork.set_featured');
        }
        $db->commit();dmo_json(200,['ok'=>true,'updated'=>count($ids),'featured'=>$target]);
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
}


/** Save bounded optional metadata edits atomically. No visibility or file changes. */
function dmo_artwork_batch_metadata(PDO $db,int $admin,array $input): never
{
    $edits=$input['edits']??null;
    if(count($input)!==3||array_diff(array_keys($input),['action','edits','confirm'])||
        ($input['confirm']??null)!==true||!is_array($edits)||!array_is_list($edits)||count($edits)<1||count($edits)>60)
        dmo_json(422,['ok'=>false,'error'=>'invalid_artwork_metadata_batch']);
    $groups=[];
    foreach($edits as $edit){
        if(!is_array($edit)||count($edit)!==4||array_diff(array_keys($edit),['id','field','expected','value'])||
            !is_int($edit['id']??null)||$edit['id']<1||!in_array($edit['field']??null,['alt','sort_order'],true))
            dmo_json(422,['ok'=>false,'error'=>'invalid_artwork_metadata_batch']);
        $id=$edit['id'];$field=$edit['field'];
        if(isset($groups[$id][$field]))dmo_json(422,['ok'=>false,'error'=>'invalid_artwork_metadata_batch']);
        foreach(['expected','value'] as $key){
            $value=$edit[$key];
            $valid=$field==='sort_order'?is_int($value)&&$value>=-10000&&$value<=10000:
                is_string($value)&&strlen($value)<=240&&preg_match('//u',$value)===1&&!preg_match('/[\x00-\x1f\x7f]/',$value);
            if(!$valid)dmo_json(422,['ok'=>false,'error'=>'invalid_artwork_metadata_batch']);
        }
        if($field==='alt')$edit['value']=trim($edit['value']);
        $groups[$id][$field]=$edit;
    }
    ksort($groups,SORT_NUMERIC);
    $db->beginTransaction();
    try{
        $lock=$db->prepare('SELECT alt_text,sort_order FROM dmo_portfolio_artworks WHERE id=:id FOR UPDATE');
        $changes=[];
        foreach($groups as $id=>$fields){
            $lock->execute(['id'=>$id]);$row=$lock->fetch(PDO::FETCH_ASSOC);
            if(!$row){$db->rollBack();dmo_json(409,['ok'=>false,'error'=>'artwork_metadata_batch_changed']);}
            foreach($fields as $field=>$edit){
                $current=$field==='alt'?(string)($row['alt_text']??''):(int)$row['sort_order'];
                // An identical retry after a lost response is a no-op.
                if($current===$edit['value'])continue;
                if($current!==$edit['expected']){$db->rollBack();dmo_json(409,['ok'=>false,'error'=>'artwork_metadata_batch_changed']);}
                $changes[]=$edit;
            }
        }
        foreach($changes as $edit){
            $field=$edit['field'];$value=$edit['value'];
            $column=$field==='alt'?'alt_text':'sort_order';
            $db->prepare('UPDATE dmo_portfolio_artworks SET '.$column.'=:value WHERE id=:id')
                ->execute(['value'=>$field==='alt'&&$value===''?null:$value,'id'=>$edit['id']]);
            dmo_artwork_record_audit($db,$admin,$edit['id'],$field==='alt'?'artwork.set_alt':'artwork.set_order');
        }
        $db->commit();
        dmo_json(200,['ok'=>true,'processed'=>count($edits),'updated'=>count($changes)]);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
