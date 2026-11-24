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
    ?array $body = null, ?string $file = null, string $origin = 'https://drawmeone.com'): array {
    $handle = curl_init('http://127.0.0.1:18865' . $route);
    if ($handle === false) throw new RuntimeException('Missing localhost cURL');
    $headers = ['Accept: application/json'];
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

    [$status,$uploaded] = art_http('/api/admin/artwork-upload.php',$token,$csrf,null,$png);
    art_assert($status===201 && is_int($uploaded['artwork_id']??null) &&
        ($uploaded['visibility']??'')==='draft',
        'valid_image_uploaded_as_private_draft');
    $id=$uploaded['artwork_id'];
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
    [$status]=art_http('/api/artwork.php?id='.$id.'&v=display');
    art_assert($status===404,'draft_public_image_denied');
    [$status]=art_http('/api/admin/artwork-preview.php?id='.$id.'&v=thumb');
    art_assert($status===401,'anonymous_draft_preview_denied');
    [$status,$json,$bytes,$headers]=art_http(
        '/api/admin/artwork-preview.php?id='.$id.'&v=display',$token);
    art_assert($status===200 && str_contains(strtolower($headers),'image/webp') &&
        str_contains(strtolower($headers),'no-store') &&
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
    [$status]=art_http('/api/artwork.php?id='.$id.'&v=original');
    art_assert($status===404,'unrecognized_public_variant_denied');
    [$status,$result]=art_http('/api/admin/artworks.php',$token,$csrf,
        ['id'=>$id,'action'=>'set_featured','featured'=>true]);
    art_assert($status===200,'featured_artwork_toggle_saved');
    [$status,$result]=art_http('/api/admin/artworks.php',$token,$csrf,
        ['id'=>$id,'action'=>'set_order','sort_order'=>-15]);
    art_assert($status===200,'artwork_sort_position_saved');
    [$status,$list]=art_http('/api/admin/artworks.php',$token);
    art_assert($status===200 && $list['artworks'][0]['featured']===true &&
        $list['artworks'][0]['sort_order']===-15,
        'artist_metadata_reflects_featured_and_sort_updates');
    [$status,$result]=art_http('/api/admin/artworks.php',$token,$csrf,
        ['id'=>$id,'action'=>'set_visibility','visibility'=>'draft']);
    art_assert($status===200,'published_drawing_can_be_unpublished');
    [$status,$manifest]=art_http('/api/portfolio.php');
    art_assert($status===200 && ($manifest['works']??null)===[],
        'unpublished_drawing_removed_from_public_manifest');
    [$status]=art_http('/api/artwork.php?id='.$id.'&v=display');
    art_assert($status===404,'unpublished_public_file_revoked');
    [$status,$json,$bytes,$headers]=art_http(
        '/api/admin/artwork-preview.php?id='.$id.'&v=thumb',$token);
    art_assert($status===200,'unpublished_artwork_still_private_artist_preview');
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
