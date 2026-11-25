<?php
declare(strict_types=1);
/**
 * Disposable private-reference API contract: real multipart upload and WebP
 * conversion, private access, cross-account denial and owner-only deletion.
 * No production credentials, customer content or outside-network requests.
 */
if (PHP_SAPI !== 'cli' ||
    getenv('DMO_CI_INTEGRATION') !== '1' ||
    getenv('DB_HOST') !== '127.0.0.1' ||
    getenv('DB_NAME') !== 'drawmeone_ci' ||
    getenv('DB_USER') !== 'ci_user') {
    fwrite(STDERR, "Refusing non-disposable image integration test.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/app/references.php';

function ref_assert(bool $condition, string $name): void
{
    if (!$condition) throw new RuntimeException('REFERENCE_TEST_FAILED=' . $name);
    echo "PASS: " . $name . "\n";
}
function ref_insert(PDO $db, string $sql, array $arguments): int
{
    $db->prepare($sql)->execute($arguments);
    return (int) $db->lastInsertId();
}
function ref_http(
    string $route, ?string $cookie = null, ?array $json = null,
    ?string $csrf = null, ?array $upload = null
): array {
    $handle = curl_init('http://127.0.0.1:18864' . $route);
    if ($handle === false) throw new RuntimeException('Local cURL unavailable');
    $headers = ['Accept: application/json', 'Origin: https://drawmeone.com'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_TIMEOUT => 9,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
    ];
    if ($cookie !== null) $opts[CURLOPT_COOKIE] = '__Host-dmo_session=' . $cookie;
    if ($csrf !== null) $headers[] = 'X-DMO-CSRF: ' . $csrf;
    if ($json !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json';
    } elseif ($upload !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = [
            'ref' => $upload['ref'],
            'reference' => new CURLFile(
                $upload['path'],
                $upload['type'],
                basename($upload['path'])
            ),
        ];
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($handle, $opts);
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);
    $text = is_string($response) ? substr($response, $headSize) : '';
    $headersText = is_string($response) ? substr($response, 0, $headSize) : '';
    $decoded = json_decode($text, true);
    return [$status, is_array($decoded) ? $decoded : null, $text, $headersText];
}
function ref_session(PDO $db, int $userId): array
{
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    ref_insert($db,
        'INSERT INTO dmo_sessions (user_id,session_hash,expires_at,last_seen_at) ' .
        'VALUES (:user,:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),UTC_TIMESTAMP())',
        ['user' => $userId, 'hash' => $hash]);
    return [$token, hash_hmac('sha256', 'dmo-csrf|' . $hash, dmo_config('DMO_APP_KEY'))];
}
function ref_clean(string $folder): void
{
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
$log = tempnam(sys_get_temp_dir(), 'dmo-ref-');
$private = sys_get_temp_dir() . '/dmo-ref-ci-' . bin2hex(random_bytes(8));
try {
    ref_assert(extension_loaded('gd') && function_exists('imagewebp'),
        'image_processing_available');
    if (!mkdir($private, 0700)) throw new RuntimeException('CI private root creation failed');
    $env = $private . '/.env';
    if (file_put_contents($env, "# Empty private CI config\n") === false) {
        throw new RuntimeException('CI private config failed');
    }
    chmod($env, 0600);
    putenv('DMO_ENV_FILE=' . $env);
    foreach ([
        'DMO_AUTH_ENABLED' => '1',
        'DMO_REGISTRATION_ENABLED' => '0',
        'DMO_MAIL_ENABLED' => '1',
        'DMO_MAIL_TRANSPORT' => 'php',
        'DMO_ALLOW_PHP_MAIL' => '1',
        'DMO_MAIL_FROM' => 'support@drawmeone.com',
        'DMO_TURNSTILE_SITE_KEY' => 'ci-local-only',
        'DMO_TURNSTILE_SECRET' => 'ci-local-only',
        'DMO_PRIVACY_PUBLISHED' => '1',
        'DMO_REQUEST_TERMS_APPROVED' => '1',
        'DMO_REFERENCES_ENABLED' => '1',
        'DMO_ADMIN_ENABLED' => '0',
    ] as $name => $value) putenv($name . '=' . $value);

    $db = dmo_database();
    $hash = password_hash('Reference-only-CI-password-2026', PASSWORD_DEFAULT);
    $customerA = ref_insert($db,
        "INSERT INTO dmo_users
         (display_name,email,password_hash,role,account_status,email_verified_at)
         VALUES ('QA Reference A','ref-a@example.invalid',:hash,'customer','active',UTC_TIMESTAMP())",
        ['hash' => $hash]);
    $customerB = ref_insert($db,
        "INSERT INTO dmo_users
         (display_name,email,password_hash,role,account_status,email_verified_at)
         VALUES ('QA Reference B','ref-b@example.invalid',:hash,'customer','active',UTC_TIMESTAMP())",
        ['hash' => $hash]);
    $refA = 'DMO-' . substr(hash('sha256', 'reference-contract-a'), 0, 20);
    $refB = 'DMO-' . substr(hash('sha256', 'reference-contract-b'), 0, 20);
    $requestA = ref_insert($db,
        "INSERT INTO dmo_commission_requests
         (public_ref,customer_id,subject_type,brief,preferred_contact,policy_version,status)
         VALUES (:ref,:customer,'portrait',:brief,'onsite','qa-policy-v1','submitted')",
        ['ref'=>$refA, 'customer'=>$customerA,
         'brief'=>str_repeat('Synthetic private image inquiry. ', 2)]);
    ref_insert($db,
        "INSERT INTO dmo_commission_requests
         (public_ref,customer_id,subject_type,brief,preferred_contact,policy_version,status)
         VALUES (:ref,:customer,'portrait',:brief,'onsite','qa-policy-v1','submitted')",
        ['ref'=>$refB, 'customer'=>$customerB,
         'brief'=>str_repeat('Other synthetic private inquiry. ', 2)]);
    [$cookieA,$csrfA] = ref_session($db,$customerA);
    [$cookieB,$csrfB] = ref_session($db,$customerB);

    $png = $private . '/test-original.png';
    $image = imagecreatetruecolor(320, 240);
    if (!$image instanceof GdImage) throw new RuntimeException('No image allocated');
    $bg = imagecolorallocate($image, 114, 164, 199);
    $fill = imagecolorallocate($image, 222, 153, 76);
    imagefilledrectangle($image, 0, 0, 319, 239, $bg);
    imagefilledellipse($image, 160, 120, 170, 132, $fill);
    imagepng($image, $png);
    imagedestroy($image);
    ref_assert(is_file($png) && filesize($png) > 100,
        'synthetic_test_image_created');

    $server = proc_open(
        [PHP_BINARY,'-S','127.0.0.1:18864','-t',
         dirname(__DIR__),__DIR__ . '/ci_router.php'],
        [0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file',$log,'w']],
        $pipes
    );
    if (!is_resource($server)) throw new RuntimeException('CI local server unavailable');
    if (isset($pipes[0])) fclose($pipes[0]);
    $ready = false;
    for ($i=0; $i<35; $i++) {
        [$code] = ref_http('/api/reference.php?id=1');
        if ($code !== 0) {$ready=true; break;}
        usleep(100000);
    }
    ref_assert($ready, 'local_reference_server_ready');
    [$code] = ref_http('/api/reference.php?id=1');
    ref_assert($code===401, 'anonymous_reference_access_denied');

    [$code] = ref_http('/api/request-attachment.php',$cookieA,null,null,
        ['ref'=>$refA,'path'=>$png,'type'=>'image/png']);
    ref_assert($code===403, 'upload_requires_csrf');
    [$code] = ref_http('/api/request-attachment.php',$cookieB,null,$csrfB,
        ['ref'=>$refA,'path'=>$png,'type'=>'image/png']);
    ref_assert($code===404, 'cross_customer_upload_denied');

    [$code,$uploaded] = ref_http('/api/request-attachment.php',
        $cookieA,null,$csrfA,['ref'=>$refA,'path'=>$png,'type'=>'image/png']);
    ref_assert($code===201 && is_int($uploaded['attachment_id']??null),
        'owner_private_image_uploaded');
    $id = $uploaded['attachment_id'];
    $stmt = $db->prepare(
        'SELECT storage_key,mime_type,pixel_width,pixel_height,byte_size ' .
        'FROM dmo_request_attachments WHERE id=:id AND request_id=:request');
    $stmt->execute(['id'=>$id,'request'=>$requestA]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    ref_assert(is_array($record) &&
        $record['mime_type']==='image/webp' &&
        $record['pixel_width'] == 320 && $record['pixel_height'] == 240 &&
        (int)$record['byte_size']>100 &&
        preg_match('~^[a-f0-9]{32}/display\.webp$~D',$record['storage_key'])===1,
        'uploaded_original_transcoded_to_private_webp');

    $privateRoot = realpath(dmo_refs_storage_root());
    $publicRoot = realpath(dirname(__DIR__));
    ref_assert($privateRoot!==false && $publicRoot!==false &&
        !str_starts_with($privateRoot . '/', $publicRoot . '/') &&
        is_file(dmo_refs_storage_file($record['storage_key'],'thumb')) &&
        is_file(dmo_refs_storage_file($record['storage_key'],'display')),
        'variants_stored_outside_public_webroot');

    require_once dirname(__DIR__).'/app/integrity.php';
    ref_assert(dmo_integrity_media($db)['references']['invalid']===0,'reference_media_metadata_consistent');
    $displayPath=dmo_refs_storage_file($record['storage_key'],'display');
    $originalBytes=file_get_contents($displayPath);
    file_put_contents($displayPath,$originalBytes.'unexpected bytes');clearstatcache(true,$displayPath);
    ref_assert(dmo_integrity_media($db)['references']['invalid']===1,'reference_size_mismatch_detected');
    file_put_contents($displayPath,$originalBytes);chmod($displayPath,0600);clearstatcache(true,$displayPath);
    [$code,$json,$bytes,$headers] = ref_http(
        '/api/reference.php?id='.$id.'&variant=display',$cookieA);
    ref_assert($code===200 && str_contains(strtolower($headers),'image/webp') &&
        str_contains(strtolower($headers),'no-store') &&
        strlen($bytes)>100 && substr($bytes,0,4)==='RIFF' &&
        substr($bytes,8,4)==='WEBP',
        'owner_can_read_private_webp_with_no_store');
    [$code] = ref_http('/api/reference.php?id='.$id.'&variant=display',$cookieB);
    ref_assert($code===404, 'cross_customer_image_read_denied');
    [$code] = ref_http('/api/reference.php?id='.$id.'&variant=display');
    ref_assert($code===401, 'anonymous_image_read_denied');

    [$code] = ref_http('/api/reference-delete.php',$cookieB,[
        'ref'=>$refA,'attachment_id'=>$id],$csrfB);
    ref_assert($code===404, 'cross_customer_delete_denied');
    [$code] = ref_http('/api/reference-delete.php',$cookieA,[
        'ref'=>$refA,'attachment_id'=>$id]);
    ref_assert($code===403, 'deletion_requires_csrf');
    [$code,$removed] = ref_http('/api/reference-delete.php',$cookieA,[
        'ref'=>$refA,'attachment_id'=>$id],$csrfA);
    ref_assert($code===200 && ($removed['reference_removed']??false)===true &&
        ($removed['storage_cleanup_complete']??false)===true,
        'owner_removes_private_reference_and_files');
    [$code] = ref_http('/api/reference.php?id='.$id,$cookieA);
    ref_assert($code===404, 'deleted_reference_not_accessible');
    $stmt->execute(['id'=>$id,'request'=>$requestA]);
    ref_assert($stmt->fetch(PDO::FETCH_ASSOC)===false,
        'deleted_reference_database_row_removed');

    $db->prepare(
        "UPDATE dmo_commission_requests SET status='completed' WHERE id=:id"
    )->execute(['id'=>$requestA]);
    [$code] = ref_http('/api/request-attachment.php',$cookieA,null,$csrfA,
        ['ref'=>$refA,'path'=>$png,'type'=>'image/png']);
    ref_assert($code===409, 'completed_request_cannot_receive_image');
    ref_assert((int)$db->query(
        'SELECT COUNT(*) FROM dmo_request_attachments')->fetchColumn()===0,
        'no_unexpected_private_references_persisted');
    echo "DISPOSABLE_REFERENCES_HTTP=PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR,'DISPOSABLE_REFERENCES_HTTP=FAIL ('.
        get_class($error).': '.$error->getMessage().")\n");
    exit(1);
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if (is_string($log)) @unlink($log);
    ref_clean($private);
}
