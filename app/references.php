<?php
declare(strict_types=1);
require_once __DIR__ . '/artwork.php';
require_once __DIR__ . '/requests.php';

const DMO_REFERENCE_MAX_BYTES = 4 * 1024 * 1024;
const DMO_REFERENCE_MAX_ITEMS = 8;

function dmo_refs_schema_ready(PDO $db): bool
{
    $q = $db->prepare(
        'SELECT version FROM dmo_schema_migrations WHERE version = :version LIMIT 1'
    );
    $q->execute(['version' => '003_private_references']);
    return $q->fetchColumn() === '003_private_references';
}

/** Existing private references may be removed even if new uploads pause. */
function dmo_refs_management_enabled(PDO $db): bool
{
    return dmo_auth_configured() && dmo_refs_schema_ready($db);
}

function dmo_refs_enabled(PDO $db): bool
{
    return dmo_auth_configured() &&
        dmo_config('DMO_REFERENCES_ENABLED') === '1' &&
        dmo_config('DMO_PRIVACY_PUBLISHED') === '1' &&
        dmo_config('DMO_REQUEST_TERMS_APPROVED') === '1' &&
        dmo_policy_current()!==null && dmo_refs_schema_ready($db);
}

function dmo_refs_storage_root(): string
{
    // Reuse the artwork root's verified outside-webroot parent, but keep
    // customer originals separate from the public artist portfolio.
    $parent = dirname(dmo_artwork_storage_root());
    $root = $parent . '/drawmeone-references';
    if (is_link($root)) {
        throw new RuntimeException('Reference root cannot be a symlink.');
    }
    return $root;
}

function dmo_refs_storage_file(string $key, string $variant): string
{
    if (!preg_match('/^[a-f0-9]{32}\/display\.webp$/D', $key) ||
        !in_array($variant, ['thumb', 'display'], true)) {
        throw new InvalidArgumentException('Unsafe reference storage key.');
    }
    $root = dmo_refs_storage_root();
    $folder = dirname($root . '/' . $key);
    $file = $folder . '/' . $variant . '.webp';
    if (is_link($folder) || is_link($file)) {
        throw new RuntimeException('Reference path cannot be a symlink.');
    }
    return $file;
}

function dmo_refs_metadata(PDO $db, int $requestId): array
{
    if (!dmo_refs_schema_ready($db)) {
        return [];
    }
    $stmt = $db->prepare(
        'SELECT id, pixel_width, pixel_height, byte_size, created_at ' .
        'FROM dmo_request_attachments WHERE request_id = :id ' .
        'ORDER BY id ASC LIMIT 8'
    );
    $stmt->execute(['id' => $requestId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'width' => (int) $row['pixel_width'],
        'height' => (int) $row['pixel_height'],
        'bytes' => (int) $row['byte_size'],
        'created_at' => (string) $row['created_at'],
    ], $rows);
}

/** Validate both variants with the same read-only contract used by diagnostics.
 * Only the display variant has stored exact dimensions and byte length.
 */
function dmo_refs_variant_valid(array $row,string $variant): bool
{
    try{
        $path=dmo_refs_storage_file((string)$row['storage_key'],$variant);
        clearstatcache(true,$path);
        if(!dmo_private_image_path_ok($path,true)||$row['mime_type']!=='image/webp')return false;
        $bytes=@filesize($path);$info=@getimagesize($path);
        if(!is_int($bytes)||$bytes<1||!is_array($info)||($info['mime']??null)!=='image/webp'||
            $info[0]<1||$info[1]<1)return false;
        if($variant==='thumb')return max($info[0],$info[1])<=480;
        return max($info[0],$info[1])<=1600&&$bytes===(int)$row['byte_size']&&
            $info[0]===(int)$row['pixel_width']&&$info[1]===(int)$row['pixel_height'];
    }catch(Throwable $e){return false;}
}

/** Request row must be locked. Never recreate a deliberately removed attachment. */
function dmo_reference_upload_replay(PDO $db,int $request,int $actor,?string $operation,?string $digest): ?array
{
    if($operation===null)return null;
    $q=$db->prepare("SELECT details_json FROM dmo_audit_log WHERE entity_type='commission_request'
        AND entity_id=:request AND actor_id=:actor AND action='request.reference_uploaded'
        AND JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.operation_key'))=:operation ORDER BY id DESC LIMIT 1");
    $q->execute(['request'=>$request,'actor'=>$actor,'operation'=>$operation]);$json=$q->fetchColumn();
    if($json===false)return null;
    $details=json_decode((string)$json,true);
    if(!is_string($details['payload_digest']??null)||!hash_equals($details['payload_digest'],(string)$digest)){
        $db->rollBack();dmo_json(409,['ok'=>false,'error'=>'operation_key_conflict']);
    }
    $q=$db->prepare('SELECT id,pixel_width,pixel_height FROM dmo_request_attachments WHERE id=:id AND request_id=:request AND uploaded_by=:actor');
    $q->execute(['id'=>$details['attachment_id'],'request'=>$request,'actor'=>$actor]);$row=$q->fetch();
    if(!is_array($row)){
        $db->rollBack();dmo_json(409,['ok'=>false,'error'=>'reference_receipt_removed']);
    }
    return ['ok'=>true,'attachment_id'=>(int)$row['id'],'width'=>(int)$row['pixel_width'],'height'=>(int)$row['pixel_height'],'already_saved'=>true];
}
