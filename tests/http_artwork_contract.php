<?php
declare(strict_types=1);
/**
 * Fully disposable Artist Desk artwork workflow:
 * authenticated upload -> private draft -> publish -> public image ->
 * feature/sort -> unpublish. No outside connections or production storage.
 */
if (PHP_SAPI !== 'cli' || getenv('DMO_CI_INTEGRATION') !== '1' ||
    getenv('DB_HOST') !== '127.0.0.1' ||
    getenv('DB_NAME') !== 'drawmeone_ci' ||
    getenv('DB_USER') !== 'ci_user') {
    fwrite(STDERR, "Refusing non-disposable artwork test.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/app/artwork.php';

function art_assert(bool $yes, string $name): void {
    if (!$yes) throw new RuntimeException('ARTWORK_CONTRACT_FAIL=' . $name);
    echo "PASS: " . $name . "\n";
}
function art_http(string $route, ?string $cookie = null, ?string $csrf = null,
    ?array $body = null, ?string $file = null, string $origin = 'https://drawmeone.com',
    ?string $ifNoneMatch = null,?string $operationKey=null,?string $skipIdentical=null): array {
    $handle = curl_init('http://127.0.0.1:18865' . $route);
    if ($handle === false) throw new RuntimeException('Missing localhost cURL');
    $headers = ['Accept: application/json'];
    if ($ifNoneMatch !== null) $headers[] = 'If-None-Match: ' . $ifNoneMatch;
    $options = [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT_MS => 2000,
    ];
    if ($cookie !== null) $options[CURLOPT_COOKIE] = '__Host-dmo_admin=' . $cookie;
    if ($csrf !== null) $headers[] = 'X-DMO-CSRF: ' . $csrf;
    if ($file !== null || $body !== null) {
        $headers[] = 'Origin: ' . $origin;
        $options[CURLOPT_POST] = true;
        if ($file !== null) {
            $options[CURLOPT_POSTFIELDS] = ['artwork' => new CURLFile($file)];
            if($operationKey!==null)$options[CURLOPT_POSTFIELDS]['operation_key']=$operationKey;
            if($skipIdentical!==null)$options[CURLOPT_POSTFIELDS]['skip_identical']=$skipIdentical;
        } else {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_THROW_ON_ERROR);
        }
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($handle, $options);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $length = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);
    $raw = is_string($response) ? substr($response, $length) : '';
    $responseHeaders = is_string($response) ? substr($response, 0, $length) : '';
    $data = json_decode($raw, true);
    return [$status, is_array($data) ? $data : null, $raw, $responseHeaders];
}
function art_purge(string $folder): void {
    if (!is_dir($folder) || is_link($folder)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
        else @unlink($item->getPathname());
    }
    @rmdir($folder);
}
$server = null;
$log = tempnam(sys_get_temp_dir(), 'dmo-artwork-http-');
$private = sys_get_temp_dir() . '/dmo-artwork-ci-' . bin2hex(random_bytes(8));
try {
    art_assert(extension_loaded('gd') && function_exists('imagewebp'),
        'webp_processor_available');
    if (!mkdir($private, 0700)) throw new RuntimeException('No private CI directory');
    $env = $private . '/.env';
    if (file_put_contents($env, "# Disposable local image test\n") === false) {
        throw new RuntimeException('No CI env file');
    }
    chmod($env, 0600);
    putenv('DMO_ENV_FILE=' . $env);
    foreach ([
        'DMO_ADMIN_ENABLED' => '1',
        'DMO_AUTH_ENABLED' => '0',
        'DMO_REGISTRATION_ENABLED' => '0',
        'DMO_ARTWORK_WRITES_ENABLED' => '1',
        'DMO_TURNSTILE_SITE_KEY' => 'ci-site-only',
        'DMO_TURNSTILE_SECRET' => 'ci-secret-only'
    ] as $name => $value) putenv($name . '=' . $value);
    $db = dmo_database();
    $password = password_hash('CI-generated-admin-only-password!', PASSWORD_DEFAULT);
    $db->prepare(
        "INSERT INTO dmo_users
         (display_name,email,password_hash,role,account_status,email_verified_at)
         VALUES ('Fake CI Artist','artwork-ci@example.invalid',:hash,'admin','active',UTC_TIMESTAMP())"
    )->execute(['hash' => $password]);
    $userId = (int) $db->lastInsertId();
    $token = bin2hex(random_bytes(32));
    $sessionHash = hash('sha256', $token);
    $db->prepare(
        "INSERT INTO dmo_sessions (user_id,session_hash,expires_at,last_seen_at)
         VALUES (:user,:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 HOUR),UTC_TIMESTAMP())"
    )->execute(['user'=>$userId,'hash'=>$sessionHash]);
    $csrf = hash_hmac('sha256', 'dmo-csrf|' . $sessionHash, dmo_config('DMO_APP_KEY'));
    $image = imagecreatetruecolor(620, 420);
    if (!$image instanceof GdImage) throw new RuntimeException('No synthetic image canvas');
    $background = imagecolorallocate($image, 242, 206, 182);
    $line = imagecolorallocate($image, 65, 51, 68);
    imagefilledrectangle($image, 0, 0, 619, 419, $background);
    imagefilledellipse($image, 310, 210, 260, 280, $line);
    $png = $private . '/synthetic-source.png';
    imagepng($image, $png);
    imagedestroy($image);
    // Minimal synthetic EXIF APP1 block: orientation 6 (90 degrees clockwise).
    $jpeg=$private.'/orientation-six.jpg';
    $fixture=imagecreatefrompng($png);imagejpeg($fixture,$jpeg,95);imagedestroy($fixture);
    $jpegBytes=file_get_contents($jpeg);
    $exif="Exif\0\0"."II".pack('vV',42,8).pack('v',1).pack('vvVv',0x0112,3,1,6)."\0\0".pack('V',0);
    file_put_contents($jpeg,substr($jpegBytes,0,2)."\xFF\xE1".pack('n',strlen($exif)+2).$exif.substr($jpegBytes,2));
    $probe=imagecreatetruecolor(3,2);
    for($i=0;$i<6;$i++)imagesetpixel($probe,$i%3,intdiv($i,3),$i+1);
    $expected=[[1,2,3,4,5,6],[3,2,1,6,5,4],[6,5,4,3,2,1],[4,5,6,1,2,3],[1,4,2,5,3,6],[4,1,5,2,6,3],[6,3,5,2,4,1],[3,6,2,5,1,4]];
    for($o=1;$o<=8;$o++){
        $copy=imagecreatetruecolor(3,2);imagecopy($copy,$probe,0,0,0,0,3,2);
        $oriented=dmo_artwork_orient($copy,$o);$pixels=[];
        for($y=0;$y<imagesy($oriented);$y++)for($x=0;$x<imagesx($oriented);$x++)$pixels[]=imagecolorat($oriented,$x,$y);
        art_assert($pixels===$expected[$o-1],'orientation_transform_'.$o);
        unset($copy,$oriented);
    }
    unset($probe);
    $bad = $private . '/not-an-image.png';
    file_put_contents($bad, 'This is not a valid artwork image');
    art_assert(is_file($png) && filesize($png) > 100, 'generated_test_artwork');
    if (!is_string($log)) throw new RuntimeException('No CI log');
    $server = proc_open([
        PHP_BINARY,'-S','127.0.0.1:18865','-t',
        dirname(__DIR__),__DIR__ . '/ci_router.php',
    ],[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file',$log,'w']],$pipes);
    if (!is_resource($server)) throw new RuntimeException('No local HTTP server');
    if(isset($pipes[0])) fclose($pipes[0]);
    $ready = false;
    for ($i=0;$i<35;$i++) {
        [$status] = art_http('/api/portfolio.php');
        if ($status!==0) { $ready=true; break; }
        usleep(100000);
    }
    art_assert($ready,'local_portfolio_server_ready');
    [$status,$manifest] = art_http('/api/portfolio.php');
    art_assert($status===200 && ($manifest['works']??null)===[],
        'public_portfolio_initially_empty');
    [$status] = art_http('/api/admin/artworks.php');
    art_assert($status===401,'anonymous_artist_library_denied');
    [$status] = art_http('/api/admin/artwork-upload.php',null,null,null,$png);
    art_assert($status===401,'anonymous_image_upload_denied');
    [$status] = art_http('/api/admin/artwork-upload.php',$token,null,null,$png);
    art_assert($status===403,'upload_requires_csrf');
    [$status] = art_http('/api/admin/artwork-upload.php',$token,$csrf,null,$png,
        'https://evil.example.invalid');
    art_assert($status===403,'upload_wrong_origin_denied');
    [$status,$badUpload] = art_http('/api/admin/artwork-upload.php',$token,$csrf,null,$bad);
    art_assert($status===422,'invalid_image_rejected');

    $lock=$db->prepare('SELECT GET_LOCK(:name,0)');$lock->execute(['name'=>dmo_artwork_upload_lock_name($db)]);
    art_assert((int)$lock->fetchColumn()===1,'disposable_upload_lock_acquired');
    try{
        [$status,$busy]=art_http('/api/admin/artwork-upload.php',$token,$csrf,null,$png);
        art_assert($status===409&&($busy['error']??null)==='artwork_upload_busy','parallel_artwork_upload_rejected_without_insert');
    }finally{
        $unlock=$db->prepare('SELECT RELEASE_LOCK(:name)');$unlock->execute(['name'=>dmo_artwork_upload_lock_name($db)]);
    }
    $uploadKey='10000000-0000-4000-8000-000000000011';
    [$status,$uploaded] = art_http('/api/admin/artwork-upload.php',$token,$csrf,null,$png,operationKey:$uploadKey);
    art_assert($status===201 && is_int($uploaded['artwork_id']??null) &&
        ($uploaded['visibility']??'')==='draft',
        'valid_image_uploaded_as_private_draft');
    $id=$uploaded['artwork_id'];
    [$status,$replay]=art_http('/api/admin/artwork-upload.php',$token,$csrf,null,$png,operationKey:$uploadKey);
    art_assert($status===200&&($replay['artwork_id']??null)===$id&&($replay['already_saved']??false),'same_upload_key_recovers_original_drawing');
    [$status,$conflict]=art_http('/api/admin/artwork-upload.php',$token,$csrf,null,$bad,operationKey:$uploadKey);
    art_assert($status===409&&($conflict['error']??null)==='operation_key_conflict','changed_upload_content_rejected');
    art_assert((int)$db->query('SELECT COUNT(*) FROM dmo_portfolio_artworks')->fetchColumn()===1,'upload_retry_does_not_duplicate_drawing');
    [$status,$same]=art_http('/api/admin/artwork-upload.php',$token,$csrf,null,$png,operationKey:'10000000-0000-4000-8000-000000000012',skipIdentical:'1');
    art_assert($status===200&&$same['identical_skipped']===true&&$same['artwork_id']===$id&&$same['visibility']==='draft','optional_exact_original_duplicate_skip');
    art_assert((int)$db->query('SELECT COUNT(*) FROM dmo_portfolio_artworks')->fetchColumn()===1,'duplicate_skip_does_not_create_row');
    [$status]=art_http('/api/admin/artwork-upload.php',$token,$csrf,null,$png,skipIdentical:'1');
    art_assert($status===422,'duplicate_skip_requires_operation_key');
    $stmt=$db->prepare(
        "SELECT a.visibility,f.variant,f.storage_key,f.mime_type,f.pixel_width,
         f.pixel_height,f.byte_size FROM dmo_portfolio_artworks a
         JOIN dmo_artwork_files f ON f.artwork_id=a.id WHERE a.id=:id ORDER BY f.variant"
    );
    $stmt->execute(['id'=>$id]);
    $variants=$stmt->fetchAll(PDO::FETCH_ASSOC);
    art_assert(count($variants)===2 && $variants[0]['visibility']==='draft' &&
        $variants[0]['mime_type']==='image/webp' &&
        $variants[1]['mime_type']==='image/webp' &&
        $variants[0]['byte_size']>100 && $variants[1]['byte_size']>100,
        'original_reencoded_into_two_private_webp_variants');
    $root=realpath(dmo_artwork_storage_root());
    $webroot=realpath(dirname(__DIR__));
    art_assert(is_string($root) && is_string($webroot) &&
        !str_starts_with($root.'/', $webroot.'/') &&
        is_file(dmo_artwork_storage_file($variants[0]['storage_key'])) &&
        is_file(dmo_artwork_storage_file($variants[1]['storage_key'])),
        'optimized_artwork_stored_outside_public_webroot');
    [$status,$manifest]=art_http('/api/portfolio.php');
    art_assert($status===200 && ($manifest['works']??null)===[],
        'draft_not_in_public_manifest');
    art_assert($manifest['has_more']===false,'small_gallery_not_truncated');
    [$status,,$directory]=art_http('/portfolio.php');
    art_assert($status===200&&!str_contains($directory,'/artwork.php?id='.$id),'draft_absent_from_server_rendered_directory');
    [$status]=art_http('/api/artwork.php?id='.$id.'&v=display');
    art_assert($status===404,'draft_public_image_denied');
    [$status,,$html,$headers]=art_http('/artwork.php?id='.$id);
    art_assert($status===404 && str_contains(strtolower($headers),'noindex') &&
        str_contains(strtolower($headers),'no-store'),'draft_detail_denied_nostore');
    [$status,,$xml]=art_http('/sitemap.php');
    art_assert($status===200 && !str_contains($xml,'artwork.php?id='.$id),
        'draft_absent_from_image_sitemap');
    [$status]=art_http('/api/admin/artwork-preview.php?id='.$id.'&v=thumb');
    art_assert($status===401,'anonymous_draft_preview_denied');
    [$status,$json,$bytes,$headers]=art_http(
        '/api/admin/artwork-preview.php?id='.$id.'&v=display',$token);
    art_assert($status===200 && str_contains(strtolower($headers),'image/webp') &&
        str_contains(strtolower($headers),'no-store') && str_contains(strtolower($headers),'noindex') &&
        substr($bytes,0,4)==='RIFF' && substr($bytes,8,4)==='WEBP',
        'artist_draft_preview_private_nostore_webp');
    [$status,$list]=art_http('/api/admin/artworks.php',$token);
    art_assert($status===200 && count($list['artworks']??[])===1 &&
        $list['artworks'][0]['id']===$id && $list['artworks'][0]['visibility']==='draft',
        'artist_library_shows_actual_draft');
    [$status]=art_http('/api/admin/artworks.php',$token,null,
        ['id'=>$id,'action'=>'set_visibility','visibility'=>'published']);
    art_assert($status===403,'publishing_requires_csrf');
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,
        ['id'=>$id,'action'=>'set_visibility','visibility'=>'published'],
        null,'https://evil.example.invalid');
    art_assert($status===403,'publishing_wrong_origin_denied');

    $path=dmo_artwork_storage_file($variants[0]['storage_key']);$saved=file_get_contents($path);
    file_put_contents($path,'broken synthetic variant');
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,['id'=>$id,'action'=>'set_visibility','visibility'=>'published']);
    art_assert($status===422,'damaged_variant_blocks_publication');
    file_put_contents($path,$saved);chmod($path,0600);
    [$status,$result]=art_http('/api/admin/artworks.php',$token,$csrf,
        ['id'=>$id,'action'=>'set_visibility','visibility'=>'published']);
    art_assert($status===200 && ($result['ok']??false)===true,
        'artist_publishes_verified_webp');
    [$status,$manifest]=art_http('/api/portfolio.php');
    art_assert($status===200 && count($manifest['works']??[])===1 &&
        str_contains($manifest['works'][0]['src'],'id='.$id) &&
        $manifest['works'][0]['width']===620 &&
        $manifest['works'][0]['height']===420 &&
        !isset($manifest['works'][0]['storage_key']),
        'published_drawing_visible_only_as_safe_public_metadata');
    [$status,$json,$bytes,$headers]=art_http('/api/artwork.php?id='.$id.'&v=thumb');
    art_assert($status===200 &&
        str_contains(strtolower($headers),'image/webp') &&
        substr($bytes,0,4)==='RIFF' && substr($bytes,8,4)==='WEBP',
        'public_published_thumbnail_loads');
    art_assert(str_contains(strtolower($headers),'no-cache') &&
        str_contains(strtolower($headers),'must-revalidate') &&
        preg_match('/^ETag:\s*("[a-f0-9]{64}")\s*$/mi',$headers,$matches)===1,
        'public_image_requires_revalidation_with_etag');
    $etag=$matches[1];
    require_once dirname(__DIR__).'/app/integrity.php';
    art_assert(dmo_integrity_media($db)['portfolio']['invalid']===0,'valid_portfolio_media_scan');
    $displayPath=dmo_artwork_storage_file($variants[0]['storage_key']);
    $savedBytes=file_get_contents($displayPath);
    file_put_contents($displayPath,$savedBytes.'unexpected bytes');
    clearstatcache(true,$displayPath);
    art_assert(dmo_integrity_media($db)['portfolio']['invalid']===1,'portfolio_size_mismatch_detected');
    [$status]=art_http('/api/artwork.php?id='.$id.'&v=display');
    art_assert($status===404,'damaged_published_image_not_served');
    [$status]=art_http('/artwork.php?id='.$id);
    art_assert($status===404,'damaged_published_detail_not_served');
    file_put_contents($displayPath,$savedBytes);chmod($displayPath,0600);clearstatcache(true,$displayPath);
    $db->beginTransaction();
    $db->prepare("DELETE FROM dmo_artwork_files WHERE artwork_id=:id AND variant='thumb'")->execute(['id'=>$id]);
    art_assert(dmo_integrity_records($db)['portfolio_missing_variants']===1,'missing_portfolio_variant_detected');
    $db->rollBack();
    art_assert(dmo_integrity_records($db)['portfolio_missing_variants']===0,'restored_portfolio_variant_consistent');
    [$status,,$cached]=art_http('/api/artwork.php?id='.$id.'&v=thumb',
        ifNoneMatch:$etag);
    art_assert($status===304 && $cached==='','published_image_conditional_get');
    [$status,,$changed]=art_http('/api/artwork.php?id='.$id.'&v=thumb',
        ifNoneMatch:'"not-current"');
    art_assert($status===200 && $changed===$bytes,'different_etag_returns_image');
    // Synthetic hostile metadata is escaped in HTML and JSON-LD.
    $label='CI drawing <script>alert("test")</script> & sample';
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,['id'=>$id,'action'=>'set_alt','alt'=>str_repeat('x',241)]);
    art_assert($status===422,'oversize_accessibility_description_denied');
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,['id'=>$id,'action'=>'set_alt','alt'=>$label]);
    art_assert($status===200,'optional_accessibility_description_saved');
    [$status,$list]=art_http('/api/admin/artworks.php',$token);
    art_assert($status===200&&$list['artworks'][0]['alt']===$label,'artist_accessibility_description_roundtrip');
    [$status,,$html,$headers]=art_http('/artwork.php?id='.$id);
    art_assert($status===200 && str_contains($html,'https://drawmeone.com/artwork.php?id='.$id) &&
        str_contains($html,'&lt;script&gt;') && !str_contains($html,'<script>alert(') &&
        str_contains($html,'"@type":"ImageObject"') &&
        str_contains(strtolower($headers),'no-cache'),'published_detail_safe_html_and_schema');
    [$status,,$directory,$headers]=art_http('/portfolio.php');
    art_assert($status===200&&str_contains($directory,'href="/artwork.php?id='.$id.'"')&&str_contains($directory,'&lt;script&gt;')&&!str_contains($directory,'<script>alert(')&&str_contains(strtolower($headers),'no-store'),'directory_public_image_and_escaped_alt');
    [$status]=art_http('/portfolio.php?page=-1');
    art_assert($status===404,'directory_invalid_page_rejected');
    [$status]=art_http('/portfolio.php?page=2');
    art_assert($status===404,'directory_empty_later_page_not_found');
    [$status,,$xml,$headers]=art_http('/sitemap.php');
    art_assert($status===200 && str_contains($xml,'<loc>https://drawmeone.com/artwork.php?id='.$id.'</loc>') &&
        str_contains($xml,'id='.$id.'&amp;v=display') &&
        str_contains(strtolower($headers),'application/xml') &&
        str_contains(strtolower($headers),'no-cache'),'published_image_sitemap_escaped_and_revalidated');
    [$status]=art_http('/api/artwork.php?id='.$id.'&v=original');
    art_assert($status===404,'unrecognized_public_variant_denied');
    [$status,$result]=art_http('/api/admin/artworks.php',$token,$csrf,
        ['id'=>$id,'action'=>'set_featured','featured'=>true]);
    art_assert($status===200,'featured_artwork_toggle_saved');
    [$status,$conflict]=art_http('/api/admin/artworks.php',$token,$csrf,['id'=>$id,'action'=>'set_featured','featured'=>false,'expected'=>false]);
    art_assert($status===409&&($conflict['error']??null)==='artwork_field_changed','stale_feature_toggle_rejected');
    [$status,$conflict]=art_http('/api/admin/artworks.php',$token,$csrf,['id'=>$id,'action'=>'set_visibility','visibility'=>'draft','expected'=>'draft']);
    art_assert($status===409&&($conflict['error']??null)==='artwork_field_changed','stale_visibility_toggle_rejected');
    [$status,$result]=art_http('/api/admin/artworks.php',$token,$csrf,
        ['id'=>$id,'action'=>'set_order','sort_order'=>-15]);
    art_assert($status===200,'artwork_sort_position_saved');
    [$status,$conflict]=art_http('/api/admin/artworks.php',$token,$csrf,['id'=>$id,'action'=>'set_order','sort_order'=>22,'expected'=>0]);
    art_assert($status===409&&($conflict['error']??null)==='artwork_field_changed','stale_sort_update_rejected');
    [$status,$conflict]=art_http('/api/admin/artworks.php',$token,$csrf,['id'=>$id,'action'=>'set_alt','alt'=>'Stale description','expected'=>'']);
    art_assert($status===409&&($conflict['error']??null)==='artwork_field_changed','stale_accessibility_description_rejected');
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,['id'=>$id,'action'=>'set_order','sort_order'=>-15,'expected'=>-15]);
    art_assert($status===200,'current_sort_expectation_accepted');
    [$status,$list]=art_http('/api/admin/artworks.php',$token);
    art_assert($status===200 && $list['artworks'][0]['featured']===true &&
        $list['artworks'][0]['sort_order']===-15,
        'artist_metadata_reflects_featured_and_sort_updates');
    art_assert($list['artworks'][0]['display_bytes']>0&&$list['artworks'][0]['thumb_bytes']>0&&array_key_exists('published_at',$list['artworks'][0]),'private_library_reports_optimized_sizes_and_publication_date');
    [$status,$result]=art_http('/api/admin/artworks.php',$token,$csrf,
        ['id'=>$id,'action'=>'set_visibility','visibility'=>'draft']);
    art_assert($status===200,'published_drawing_can_be_unpublished');
    [$status,$manifest]=art_http('/api/portfolio.php');
    art_assert($status===200 && ($manifest['works']??null)===[],
        'unpublished_drawing_removed_from_public_manifest');
    [$status,,$directory]=art_http('/portfolio.php');
    art_assert($status===200&&!str_contains($directory,'/artwork.php?id='.$id),'unpublished_removed_from_directory');
    [$status]=art_http('/api/artwork.php?id='.$id.'&v=display');
    art_assert($status===404,'unpublished_public_file_revoked');
    [$status]=art_http('/api/artwork.php?id='.$id.'&v=display&retry=123-1');
    art_assert($status===404,'explicit_image_retry_cannot_bypass_unpublish');
    [$status,,$body,$headers]=art_http('/api/artwork.php?id='.$id.'&v=thumb',
        ifNoneMatch:$etag);
    art_assert($status===404 && $body==='' && str_contains(strtolower($headers),'no-store'),
        'unpublished_image_rejects_previous_etag');
    [$status,,$html,$headers]=art_http('/artwork.php?id='.$id);
    art_assert($status===404 && str_contains(strtolower($headers),'noindex'),
        'unpublished_detail_revoked');
    [$status,,$xml]=art_http('/sitemap.php');
    art_assert($status===200 && !str_contains($xml,'artwork.php?id='.$id),
        'unpublished_removed_from_image_sitemap');
    [$status,$json,$bytes,$headers]=art_http(
        '/api/admin/artwork-preview.php?id='.$id.'&v=thumb',$token);
    art_assert($status===200,'unpublished_artwork_still_private_artist_preview');
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,['id'=>$id,'action'=>'set_alt','alt'=>'']);
    art_assert($status===200,'optional_description_can_be_cleared');
    [$status,$second]=art_http('/api/admin/artwork-upload.php',$token,$csrf,null,function_exists('exif_read_data')?$jpeg:$png);
    art_assert($status===201,'second_private_drawing_for_batch');
    $id2=$second['artwork_id'];
    if(function_exists('exif_read_data')){
        art_assert($second['width']===420&&$second['height']===620,'jpeg_exif_orientation_normalized');
        [$status,,$orientedBytes]=art_http('/api/admin/artwork-preview.php?id='.$id2.'&v=display',$token);
        $size=getimagesizefromstring($orientedBytes);
        art_assert($status===200&&$size[0]===420&&$size[1]===620&&strpos($orientedBytes,"Exif\0\0")===false,'oriented_private_webp_without_exif');
    }
    $featureBatch=['action'=>'batch_featured','ids'=>[$id,$id2],'featured'=>false,'confirm'=>true];
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,$featureBatch);
    art_assert($status===409,'mixed_feature_batch_rejected_atomically');
    art_assert((int)$db->query('SELECT is_featured FROM dmo_portfolio_artworks WHERE id='.(int)$id)->fetchColumn()===1,'failed_feature_batch_preserves_existing_flag');
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,array_replace($featureBatch,['ids'=>[$id]]));
    art_assert($status===200,'feature_batch_removes_flag');
    [$status,$features]=art_http('/api/admin/artworks.php',$token,$csrf,array_replace($featureBatch,['featured'=>true]));
    art_assert($status===200&&$features['updated']===2,'atomic_feature_batch');
    [$status,$privateManifest]=art_http('/api/portfolio.php');
    art_assert($status===200&&$privateManifest['works']===[],'feature_drafts_never_publishes');
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,array_replace($featureBatch,['ids'=>[$id2]]));
    art_assert($status===200,'restore_second_fixture_feature_flag');
    $batch=['action'=>'batch_visibility','ids'=>[$id,$id2],'visibility'=>'published','confirm'=>true];
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,array_replace($batch,['ids'=>[$id,$id]]));
    art_assert($status===422,'batch_duplicate_ids_denied');
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,array_replace($batch,['confirm'=>false]));
    art_assert($status===422,'batch_requires_confirmation');
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,array_replace($batch,['ids'=>[$id,PHP_INT_MAX]]));
    art_assert($status===409,'batch_stale_or_missing_item_rejected');
    $q=$db->prepare("SELECT storage_key FROM dmo_artwork_files WHERE artwork_id=:id AND variant='display'");$q->execute(['id'=>$id2]);
    $file=dmo_artwork_storage_file($q->fetchColumn());$backup=file_get_contents($file);file_put_contents($file,'broken second drawing');
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,$batch);
    art_assert($status===422,'batch_invalid_file_rejected');
    [$status,$manifest]=art_http('/api/portfolio.php');
    art_assert($status===200&&$manifest['works']===[],'failed_batch_publishes_nothing');
    file_put_contents($file,$backup);chmod($file,0600);
    [$status,$result]=art_http('/api/admin/artworks.php',$token,$csrf,$batch);
    art_assert($status===200&&$result['updated']===2,'atomic_batch_publish');
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,$batch);
    art_assert($status===409,'stale_batch_replay_rejected');
    [$status,$manifest]=art_http('/api/portfolio.php');
    art_assert($status===200&&count($manifest['works'])===2,'both_published_together');
    [$status,,$featuredDirectory]=art_http('/portfolio.php?view=featured');
    art_assert($status===200&&str_contains($featuredDirectory,'href="/artwork.php?id='.$id.'&amp;view=featured"')&&!str_contains($featuredDirectory,'href="/artwork.php?id='.$id2.'&amp;view=featured"'),'featured_filter_only_featured_published_art');
    [$status,,$landscapeDirectory]=art_http('/portfolio.php?view=landscape');
    art_assert($status===200&&str_contains($landscapeDirectory,'href="/artwork.php?id='.$id.'&amp;view=landscape"'),'landscape_filter_includes_landscape');
    if(function_exists('exif_read_data')){
        art_assert(!str_contains($landscapeDirectory,'href="/artwork.php?id='.$id2.'&amp;view=landscape"'),'landscape_excludes_oriented_portrait');
        [$status,,$portraitDirectory]=art_http('/portfolio.php?view=portrait');
        art_assert($status===200&&str_contains($portraitDirectory,'href="/artwork.php?id='.$id2.'&amp;view=portrait"')&&!str_contains($portraitDirectory,'href="/artwork.php?id='.$id.'&amp;view=portrait"'),'portrait_filter_uses_oriented_dimensions');
    }
    // Earlier coverage intentionally cleared this description. Restore the
    // synthetic search fixture before exercising the public search.
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,['id'=>$id,'action'=>'set_alt','alt'=>$label]);
    art_assert($status===200,'search_description_fixture_restored');
    [$status,,$searched]=art_http('/portfolio.php?q='.urlencode('<script>'));
    art_assert($status===200&&str_contains($searched,'Showing 1–1 of 1 published drawing')&&str_contains($searched,'href="/artwork.php?id='.$id.'&amp;q=%3Cscript%3E"')&&!str_contains($searched,'<script>alert('),'portfolio_literal_description_search_escaped');
    [$status,,$none]=art_http('/portfolio.php?q=%25');
    art_assert($status===200&&!str_contains($none,'href="/artwork.php?id=')&&str_contains($none,'No published drawings match this search'),'portfolio_search_percent_is_literal');
    [$status]=art_http('/portfolio.php?q%5B%5D=bad');
    art_assert($status===422,'portfolio_search_rejects_array');
    [$status,,$searchDetail]=art_http('/artwork.php?id='.$id.'&q='.urlencode('<script>'));
    art_assert($status===200&&str_contains($searchDetail,'Drawing 1 of 1 matching your search')&&str_contains($searchDetail,'href="/portfolio.php?q=%3Cscript%3E"')&&!str_contains($searchDetail,'rel="next"'),'portfolio_search_detail_context');
    [$status,,$staleSearch]=art_http('/artwork.php?id='.$id2.'&q='.urlencode('<script>').'&from_page=99');
    art_assert($status===200&&str_contains($staleSearch,'href="/portfolio.php"')&&str_contains($staleSearch,'Drawing 2 of 2'),'stale_search_and_page_recover');
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,['id'=>$id,'action'=>'set_alt','alt'=>'']);
    art_assert($status===200,'search_fixture_description_cleared');
    [$status]=art_http('/portfolio.php?view=invalid');
    art_assert($status===404,'invalid_portfolio_filter_rejected');
    [$status,,$firstPage]=art_http('/artwork.php?id='.$id);
    art_assert($status===200&&str_contains($firstPage,'rel="next" href="/artwork.php?id='.$id2.'"')&&!str_contains($firstPage,'rel="prev"'),'featured_drawing_next_navigation');
    [$status,,$secondPage]=art_http('/artwork.php?id='.$id2);
    art_assert($status===200&&str_contains($secondPage,'rel="prev" href="/artwork.php?id='.$id.'"')&&!str_contains($secondPage,'rel="next"'),'last_drawing_previous_navigation');
    [$status,,$filteredDetail]=art_http('/artwork.php?id='.$id.'&view=featured');
    art_assert($status===200&&!str_contains($filteredDetail,'rel="next"')&&str_contains($filteredDetail,'href="/portfolio.php?view=featured"'),'filtered_detail_stays_in_featured_selection');
    art_assert(str_contains($filteredDetail,'data-copy-artwork')&&str_contains($filteredDetail,'https://drawmeone.com/artwork.php?id='.$id.'"'),'share_uses_canonical_published_drawing_link');
    [$status,,$fallbackDetail]=art_http('/artwork.php?id='.$id2.'&view=featured');
    art_assert($status===200&&str_contains($fallbackDetail,'rel="prev" href="/artwork.php?id='.$id.'"'),'stale_filter_falls_back_to_all');
    $metadata=['action'=>'batch_metadata','confirm'=>true,'edits'=>[
        ['id'=>$id,'field'=>'sort_order','expected'=>-15,'value'=>-14],
        ['id'=>$id2,'field'=>'alt','expected'=>'','value'=>'Synthetic second description'],
    ]];
    [$status,$savedMetadata]=art_http('/api/admin/artworks.php',$token,$csrf,$metadata);
    art_assert($status===200&&$savedMetadata['updated']===2&&$savedMetadata['processed']===2,'atomic_metadata_batch');
    [$status,$replayMetadata]=art_http('/api/admin/artworks.php',$token,$csrf,$metadata);
    art_assert($status===200&&$replayMetadata['updated']===0,'metadata_batch_retry_noop');
    $conflict=$metadata;$conflict['edits'][0]['expected']=-14;$conflict['edits'][0]['value']=-13;$conflict['edits'][1]['value']='Conflict';
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,$conflict);
    art_assert($status===409&&(int)$db->query('SELECT sort_order FROM dmo_portfolio_artworks WHERE id='.(int)$id)->fetchColumn()===-14,'metadata_conflict_rolls_back_all');
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,array_replace($metadata,['confirm'=>false]));
    art_assert($status===422,'metadata_batch_requires_confirmation');
    $restore=['action'=>'batch_metadata','confirm'=>true,'edits'=>[
        ['id'=>$id,'field'=>'sort_order','expected'=>-14,'value'=>-15],
        ['id'=>$id2,'field'=>'alt','expected'=>'Synthetic second description','value'=>''],
    ]];
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,$restore);
    art_assert($status===200,'metadata_restore_fixture');
    // Put both in the same featured group with a tied rank, then move
    // against the normal ID tiebreaker and verify the public order.
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,['id'=>$id2,'action'=>'set_featured','featured'=>true]);
    art_assert($status===200,'second_featured_for_curation');
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,['id'=>$id2,'action'=>'set_order','sort_order'=>-15]);
    art_assert($status===200,'equal_gallery_rank_fixture');
    $move=['action'=>'move_published','id'=>$id,'neighbor_id'=>$id2,'direction'=>'earlier','expected_order'=>-15,'expected_neighbor_order'=>-15,'expected_featured'=>true];
    [$status,$moved]=art_http('/api/admin/artworks.php',$token,$csrf,$move);
    art_assert($status===200&&$moved['updated']===2,'gallery_move_rebalances_tied_ranks');
    [$status,,$filteredDetail]=art_http('/artwork.php?id='.$id.'&view=featured');
    art_assert($status===200&&str_contains($filteredDetail,'rel="next" href="/artwork.php?id='.$id2.'&amp;view=featured"'),'filtered_next_preserves_view');
    [$status,,$firstPage]=art_http('/artwork.php?id='.$id);
    art_assert($status===200&&str_contains($firstPage,'rel="next" href="/artwork.php?id='.$id2.'"'),'gallery_move_visible_publicly');
    [$status]=art_http('/api/admin/artworks.php',$token,$csrf,$move);
    art_assert($status===409,'stale_gallery_move_rejected');
    [$status,$result]=art_http('/api/admin/artworks.php',$token,$csrf,array_replace($batch,['visibility'=>'draft']));
    art_assert($status===200&&$result['updated']===2,'atomic_batch_unpublish');
    [$status,$manifest]=art_http('/api/portfolio.php');
    art_assert($status===200&&$manifest['works']===[],'both_removed_together');
    [$status,,$featuredDirectory]=art_http('/portfolio.php?view=featured');
    art_assert($status===200&&!str_contains($featuredDirectory,'href="/artwork.php?id='),'filtered_directory_excludes_unpublished_art');
    [$status]=art_http('/artwork.php?id='.$id2);
    art_assert($status===404,'adjacent_drawing_unpublished_detail_revoked');
    $audit=$db->query(
        "SELECT action FROM dmo_audit_log WHERE entity_type='portfolio_artwork'"
    )->fetchAll(PDO::FETCH_COLUMN);
    art_assert(count($audit)>=5 &&
        in_array('artwork.uploaded_as_draft',$audit,true) &&
        in_array('artwork.set_visibility',$audit,true),
        'all_artwork_mutations_audited');
    art_assert((int)$db->query('SELECT COUNT(*) FROM dmo_payments')->fetchColumn()===0,
        'no_payment_records_created_by_artwork_workflow');
    echo "DISPOSABLE_ARTWORK_HTTP=PASS\n";
} catch(Throwable $error) {
    fwrite(STDERR, "DISPOSABLE_ARTWORK_HTTP=FAIL (" . get_class($error) .
        ': ' . $error->getMessage() . ")\n");
    exit(1);
} finally {
    if (is_resource($server)) {proc_terminate($server);proc_close($server);}
    if (is_string($log)) @unlink($log);
    art_purge($private);
}
