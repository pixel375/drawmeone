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
require_once dirname(__DIR__) . '/app/deliveries.php';

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
    $handle = curl_init('http://127.0.0.1:18865' . $route);
    if ($handle === false) throw new RuntimeException('Local cURL unavailable');
    $headers = ['Accept: application/json', 'Origin: https://drawmeone.com'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 1500,
        CURLOPT_TIMEOUT => 9,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER => true,
    ];
    if ($cookie !== null) $opts[CURLOPT_COOKIE] = (str_starts_with($cookie,'admin:')?'__Host-dmo_admin='.substr($cookie,6):'__Host-dmo_session='.$cookie);
    if ($csrf !== null) $headers[] = 'X-DMO-CSRF: ' . $csrf;
    if ($json !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json';
    } elseif ($upload !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = [
            'request_id' => (string)$upload['request_id'], 'order_id'=>(string)$upload['order_id'],
            'artwork' => new CURLFile(
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
        'DMO_ADMIN_ENABLED' => '1',
        'DMO_REQUEST_ADMIN_WRITES_ENABLED'=>'1','DMO_ORDER_ADMIN_ENABLED'=>'1','DMO_ORDER_TERMS_APPROVED'=>'1','DMO_DELIVERY_DRAFTS_ENABLED'=>'1',
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

    $artist=ref_insert($db,"INSERT INTO dmo_users (display_name,email,password_hash,role,account_status,email_verified_at) VALUES ('CI Artist','delivery-artist@example.invalid',:hash,'admin','active',UTC_TIMESTAMP())",['hash'=>$hash]);
    [$artistCookie,$artistCsrf]=ref_session($db,$artist);
    $artistCookie='admin:'.$artistCookie;
    $quote=ref_insert($db,"INSERT INTO dmo_quotes (request_id,version_number,currency,total_minor,scope_description,revision_allowance,status,sent_at,accepted_at) VALUES (:request,1,'CAD',3000,'Synthetic artwork',1,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP())",['request'=>$requestA]);
    $order=ref_insert($db,"INSERT INTO dmo_orders (request_id,accepted_quote_id,status,payment_status) VALUES (:request,:quote,'awaiting_arrangement','unverified')",['request'=>$requestA,'quote'=>$quote]);
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
        [PHP_BINARY,'-S','127.0.0.1:18865','-t',
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

    $upload=['request_id'=>$requestA,'order_id'=>$order,'path'=>$png,'type'=>'image/png'];
    [$code]=ref_http('/api/admin/delivery-draft.php',$cookieA,null,$csrfA,$upload);
    ref_assert(in_array($code,[401,403],true),'customer_cannot_stage_delivery');
    [$code]=ref_http('/api/admin/delivery-draft.php',$artistCookie,null,null,$upload);
    ref_assert($code===403,'artist_upload_requires_csrf');
    [$code]=ref_http('/api/admin/delivery-draft.php',$artistCookie,null,$artistCsrf,array_replace($upload,['order_id'=>$order+99]));
    ref_assert($code===409,'unrelated_order_rejected');
    [$code,$saved]=ref_http('/api/admin/delivery-draft.php',$artistCookie,null,$artistCsrf,$upload);
    ref_assert($code===201 && $saved['customer_visible']===false,'artist_stages_private_draft');
    $id=$saved['draft_id'];
    [$code,$json,$bytes,$headers]=ref_http('/api/admin/delivery-draft.php?id='.$id,$artistCookie);
    ref_assert($code===200 && str_contains(strtolower($headers),'no-store') && substr($bytes,8,4)==='WEBP','artist_downloads_private_webp');
    foreach([$cookieA,$cookieB,null] as $cookie){
        [$code]=ref_http('/api/admin/delivery-draft.php?id='.$id,$cookie);
        ref_assert(in_array($code,[401,403],true),'customer_or_anonymous_cannot_download_draft');
    }
    [$code,$data]=ref_http('/api/request.php?ref='.$refA,$cookieA);
    ref_assert($code===200 && !isset($data['delivery_drafts']),'customer_detail_excludes_staging');
    $row=$db->query('SELECT storage_key,sha256 FROM dmo_delivery_drafts')->fetch(PDO::FETCH_ASSOC);
    ref_assert(hash_equals($row['sha256'],hash_file('sha256',dmo_delivery_file($row['storage_key']))),'draft_integrity_hash_matches');
    $path=dmo_delivery_file($row['storage_key']);
    [$code,$json,$bytes,$headers]=ref_http('/api/admin/delivery-draft.php?id='.$id.'&view=preview',$artistCookie);
    ref_assert($code===200 && str_contains(strtolower($headers),'content-disposition: inline'),'artist_private_preview');
    $original=file_get_contents($path);
    file_put_contents($path,'CI checksum mismatch');
    [$code]=ref_http('/api/admin/delivery-draft.php?id='.$id,$artistCookie);
    ref_assert($code===404,'corrupt_draft_cannot_be_downloaded');
    file_put_contents($path,$original);
    $remove=['action'=>'remove_delivery_draft','request_id'=>$requestA,'draft_id'=>$id,'confirm_remove'=>true];
    [$code]=ref_http('/api/admin/request-actions.php',$cookieA,$remove,$csrfA);
    ref_assert(in_array($code,[401,403],true),'customer_cannot_remove_draft');
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,$remove);
    ref_assert($code===403,'draft_removal_requires_csrf');
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,array_replace($remove,['confirm_remove'=>false]),$artistCsrf);
    ref_assert($code===422,'explicit_draft_removal_confirmation_required');
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,array_replace($remove,['request_id'=>$requestA+1]),$artistCsrf);
    ref_assert($code===404,'unrelated_request_cannot_remove_draft');
    [$code,$result]=ref_http('/api/admin/request-actions.php',$artistCookie,$remove,$artistCsrf);
    ref_assert($code===200 && $result['draft_removed']===true && $result['storage_cleanup_complete']===true && !file_exists($path),'artist_removes_private_draft_and_file');
    [$code]=ref_http('/api/admin/delivery-draft.php?id='.$id,$artistCookie);
    ref_assert($code===404,'removed_draft_cannot_be_downloaded');
    ref_assert($db->query('SELECT payment_status FROM dmo_orders')->fetchColumn()==='unverified','staging_does_not_verify_payment');
    echo "DISPOSABLE_DELIVERY_DRAFTS=PASS\n";
} catch(Throwable $error){
    fwrite(STDERR,"DISPOSABLE_DELIVERY_DRAFTS=FAIL ".$error->getMessage()."\n");exit(1);
}finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if(is_string($log))@unlink($log);
    ref_clean($private);
}
