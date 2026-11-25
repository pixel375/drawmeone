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
        $file=new CURLFile($upload['path'],$upload['type'],basename($upload['path']));
        $opts[CURLOPT_POSTFIELDS] = isset($upload['ref'])?
            ['ref'=>$upload['ref'],'reference'=>$file]:
            ['request_id'=>(string)$upload['request_id'],'order_id'=>(string)$upload['order_id'],'artwork'=>$file];
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
        'DMO_REQUESTS_ENABLED'=>'1','DMO_QUOTE_DELIVERY_ENABLED'=>'1','DMO_QUOTE_RESPONSES_ENABLED'=>'1','DMO_MANUAL_PAYMENT_ENABLED'=>'1','DMO_MANUAL_PAYMENT_TERMS_APPROVED'=>'1','DMO_CHECKOUT_LINKS_ENABLED'=>'1','DMO_CHECKOUT_TERMS_APPROVED'=>'1','DMO_CHECKOUT_HOST'=>'checkout.example.invalid','DMO_CHECKOUT_PATH_PREFIX'=>'/invoice/','DMO_PRIVACY_SELF_SERVICE_ENABLED'=>'1','DMO_CUSTOMER_MESSAGING_ENABLED'=>'1',
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
        'DMO_REQUEST_ADMIN_WRITES_ENABLED'=>'1','DMO_ORDER_ADMIN_ENABLED'=>'1','DMO_ORDER_TERMS_APPROVED'=>'1','DMO_DELIVERY_DRAFTS_ENABLED'=>'1','DMO_DELIVERY_RELEASE_ENABLED'=>'1','DMO_DELIVERY_TERMS_APPROVED'=>'1','DMO_ARTWORK_REVIEW_ENABLED'=>'1','DMO_PAYMENT_EXCEPTIONS_ENABLED'=>'1','DMO_PAYMENT_EXCEPTIONS_TERMS_APPROVED'=>'1',
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
    $oldPin=hash_file('sha256',__DIR__.'/fixtures/policies/qa-policy-v0.json');
    $db->prepare("UPDATE dmo_commission_requests SET policy_version='qa-policy-v0' WHERE id=:id")->execute(['id'=>$requestA]);
    $db->prepare("INSERT INTO dmo_audit_log (actor_id,action,entity_type,entity_id,details_json) VALUES (:actor,'request.submitted','commission_request',:id,:details)")
        ->execute(['actor'=>$customerA,'id'=>$requestA,'details'=>json_encode(['policy_version'=>'qa-policy-v0','policy_sha256'=>$oldPin],JSON_THROW_ON_ERROR)]);
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
    $audit=$db->prepare("UPDATE dmo_audit_log SET details_json=:details WHERE entity_type='commission_request' AND entity_id=:id AND action='request.submitted'");
    $audit->execute(['id'=>$requestA,'details'=>json_encode(['policy_version'=>'qa-policy-v0','policy_sha256'=>str_repeat('0',64)],JSON_THROW_ON_ERROR)]);
    [$code]=ref_http('/api/admin/delivery-draft.php',$artistCookie,null,$artistCsrf,$upload);
    ref_assert($code===409,'historical_receipt_mismatch_blocks_staging');
    $audit->execute(['id'=>$requestA,'details'=>json_encode(['policy_version'=>'qa-policy-v0','policy_sha256'=>$oldPin],JSON_THROW_ON_ERROR)]);
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
    $advance=['action'=>'set_production_status','request_id'=>$requestA,'order_id'=>$order,'expected_status'=>'awaiting_arrangement','status'=>'in_progress'];
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,$advance,$artistCsrf);
    ref_assert($code===409,'unpaid_order_cannot_start_production');
    $db->prepare("UPDATE dmo_orders SET payment_status='paid' WHERE id=:id")->execute(['id'=>$order]);
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,$advance,$artistCsrf);
    ref_assert($code===409,'paid_flag_without_evidence_cannot_start');
    $db->prepare("INSERT INTO dmo_payments (order_id,method,currency,amount_minor,provider_reference,status,verified_by,verified_at) VALUES (:order,'bank_transfer','CAD',3000,:ref,'verified',:artist,UTC_TIMESTAMP())")->execute(['order'=>$order,'ref'=>'manual-hmac-v1:'.str_repeat('a',64),'artist'=>$artist]);
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,$advance,$artistCsrf);
    ref_assert($code===200,'verified_payment_allows_production');
    $ready=array_replace($advance,['expected_status'=>'in_progress','status'=>'ready_for_delivery']);
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,$ready,$artistCsrf);
    ref_assert($code===409,'ready_requires_staged_artwork');
    [$code,$saved]=ref_http('/api/admin/delivery-draft.php',$artistCookie,null,$artistCsrf,$upload);
    ref_assert($code===201,'stage_final_artwork');
    $proofId=$saved['draft_id'];
    $offer=['action'=>'offer_artwork_review','request_id'=>$requestA,'order_id'=>$order,'draft_id'=>$proofId];
    [$code,$offered]=ref_http('/api/admin/request-actions.php',$artistCookie,$offer,$artistCsrf);
    ref_assert($code===201,'artist_offers_private_proof');
    $reviewId=$offered['review_id'];
    require_once dirname(__DIR__).'/app/notifications.php';
    $db->prepare("UPDATE dmo_commission_requests SET preferred_contact='email' WHERE id=:id")->execute(['id'=>$requestA]);
    ref_assert(dmo_notification_target($db,$requestA,'proof',$reviewId)!==null,'proof_notice_target_eligible');
    ref_assert(dmo_notification_target($db,$requestB,'proof',$reviewId)===null,'proof_notice_cross_request_denied');
    [$code,$noticeDetail]=ref_http('/api/admin/request.php?id='.$requestA,$artistCookie);
    ref_assert($code===200&&count(array_filter($noticeDetail['notification_options'],fn($n)=>$n['kind']==='proof'&&$n['event_id']===$reviewId))===1,'proof_notice_listed');
    [$code,$json,$bytes,$headers]=ref_http('/api/artwork-review.php?id='.$reviewId,$cookieA);
    ref_assert($code===200 && substr($bytes,8,4)==='WEBP' && str_contains(strtolower($headers),'no-store'),'customer_sees_watermarked_proof');
    [$code]=ref_http('/api/artwork-review.php?id='.$reviewId,$cookieB);
    ref_assert($code===404,'other_customer_cannot_view_proof');
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,$ready,$artistCsrf);
    ref_assert($code===409,'pending_proof_blocks_ready');
    $decision=['review_id'=>$reviewId,'decision'=>'revise','feedback'=>'Please adjust shading.'];
    [$code]=ref_http('/api/artwork-review.php',$cookieB,$decision,$csrfB);
    ref_assert($code===409,'other_customer_cannot_request_revision');
    [$code]=ref_http('/api/artwork-review.php',$cookieA,$decision);
    ref_assert($code===403,'review_requires_csrf');
    [$code]=ref_http('/api/artwork-review.php',$cookieA,$decision,$csrfA);
    ref_assert($code===200,'customer_uses_included_revision');
    ref_assert(dmo_notification_target($db,$requestA,'proof',$reviewId)===null,'responded_proof_notice_denied');
    [$code]=ref_http('/api/artwork-review.php',$cookieA,$decision,$csrfA);
    ref_assert($code===409,'revision_replay_denied');
    [$code,$saved]=ref_http('/api/admin/delivery-draft.php',$artistCookie,null,$artistCsrf,$upload);
    ref_assert($code===201,'artist_stages_revised_proof');
    $finalId=$saved['draft_id'];
    [$code,$offered]=ref_http('/api/admin/request-actions.php',$artistCookie,array_replace($offer,['draft_id'=>$finalId]),$artistCsrf);
    ref_assert($code===201,'artist_offers_revised_proof');
    $reviewId=$offered['review_id'];
    [$code]=ref_http('/api/artwork-review.php',$cookieA,array_replace($decision,['review_id'=>$reviewId]),$csrfA);
    ref_assert($code===409,'included_revision_limit_enforced');
    [$code]=ref_http('/api/artwork-review.php',$cookieA,['review_id'=>$reviewId,'decision'=>'approve','feedback'=>''],$csrfA);
    ref_assert($code===200,'customer_approves_exact_revised_proof');
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,$ready,$artistCsrf);
    ref_assert($code===200,'artist_marks_ready');
    [$code,$data]=ref_http('/api/request.php?ref='.$refA,$cookieA);
    ref_assert($code===200 && $data['deliveries']===[],'ready_is_not_released');
    [$code]=ref_http('/api/delivery.php?id=1',$cookieA);
    ref_assert($code===404,'no_download_before_release');
    $release=['action'=>'release_delivery','request_id'=>$requestA,'order_id'=>$order,'draft_id'=>$finalId,'confirm_final'=>true];
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,array_replace($release,['confirm_final'=>false]),$artistCsrf);
    ref_assert($code===422,'explicit_final_release_required');
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,array_replace($release,['draft_id'=>$proofId]),$artistCsrf);
    ref_assert($code===409,'cannot_release_unapproved_draft');
    [$code,$result]=ref_http('/api/admin/request-actions.php',$artistCookie,$release,$artistCsrf);
    ref_assert($code===201 && $result['status']==='completed' && $result['customer_visible']===true,'final_release_completes_order');
    $delivery=$result['delivery_id'];
    ref_assert(dmo_notification_target($db,$requestA,'delivery',$delivery)!==null,'completed_delivery_notice_eligible');
    ref_assert(dmo_notification_target($db,$requestB,'delivery',$delivery)===null,'delivery_notice_cross_request_denied');
    [$code,$data]=ref_http('/api/request.php?ref='.$refA,$cookieA);
    ref_assert($code===200 && count($data['deliveries'])===1 && $data['deliveries'][0]['id']===$delivery && !isset($data['deliveries'][0]['storage_key']),'owner_sees_safe_delivery_metadata');
    [$code,$json,$bytes,$headers]=ref_http('/api/delivery.php?id='.$delivery,$cookieA);
    ref_assert($code===200 && substr($bytes,8,4)==='WEBP' && str_contains(strtolower($headers),'no-store'),'owner_downloads_final_artwork');
    [$code]=ref_http('/api/delivery.php?id='.$delivery,$cookieB);
    ref_assert($code===404,'other_customer_cannot_download_final');
    [$code]=ref_http('/api/delivery.php?id='.$delivery);
    ref_assert($code===401,'anonymous_cannot_download_final');
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,$release,$artistCsrf);
    ref_assert($code===409,'duplicate_release_denied');
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,array_replace($remove,['draft_id'=>$finalId]),$artistCsrf);
    ref_assert($code===409,'released_draft_cannot_be_removed');
    $hold=['action'=>'set_payment_exception','request_id'=>$requestA,'order_id'=>$order,'decision'=>'hold','reason'=>'dispute','expected_state'=>'paid','confirm_external_check'=>true];
    [$code]=ref_http('/api/admin/request-actions.php',$cookieA,$hold,$csrfA);
    ref_assert(in_array($code,[401,403],true),'customer_cannot_change_payment_hold');
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,$hold,$artistCsrf);
    ref_assert($code===200,'artist_records_payment_hold');
    ref_assert(dmo_notification_target($db,$requestA,'delivery',$delivery)===null,'payment_hold_blocks_delivery_notice');
    [$code]=ref_http('/api/delivery.php?id='.$delivery,$cookieA);
    ref_assert($code===404,'hold_revokes_future_download');
    [$code,$data]=ref_http('/api/request.php?ref='.$refA,$cookieA);
    ref_assert($code===200 && $data['orders'][0]['payment_status']==='on_hold' && $data['deliveries']===[],'customer_sees_hold_without_file_access');
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,$hold,$artistCsrf);
    ref_assert($code===409,'stale_hold_rejected');
    $restore=array_replace($hold,['decision'=>'restore','reason'=>'settled','expected_state'=>'on_hold']);
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,$restore,$artistCsrf);
    ref_assert($code===200,'settled_payment_restores_access');
    [$code]=ref_http('/api/delivery.php?id='.$delivery,$cookieA);
    ref_assert($code===200,'restored_customer_download');
    $refund=array_replace($hold,['decision'=>'refund','reason'=>'full_external_refund']);
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,$refund,$artistCsrf);
    ref_assert($code===200,'external_full_refund_recorded');
    [$code]=ref_http('/api/delivery.php?id='.$delivery,$cookieA);
    ref_assert($code===404,'refund_blocks_future_download');
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,$restore,$artistCsrf);
    ref_assert($code===409,'refund_cannot_be_restored_as_hold');
    $db->prepare("UPDATE dmo_orders SET payment_status='paid' WHERE id=:id")->execute(['id'=>$order]);
    [$code]=ref_http('/api/delivery.php?id='.$delivery,$cookieA);
    ref_assert($code===404,'download_rechecks_current_payment_state');
    require_once dirname(__DIR__).'/app/integrity.php';
    $issues=dmo_integrity_records($db);
    ref_assert($issues['paid_without_evidence']===1 && $issues['paid_order_exception_conflict']===1,'integrity_detects_paid_flag_conflicting_with_refund');
    $db->prepare("UPDATE dmo_orders SET payment_status='unverified' WHERE id=:id")->execute(['id'=>$order]);
    ref_assert(array_sum(dmo_integrity_records($db))===0,'consistent_refunded_order_passes_integrity');
    $media=dmo_integrity_media($db);
    ref_assert($media['deliveries']['checked']===2 && $media['deliveries']['invalid']===0 && $media['deliveries']['complete'],'integrity_checks_private_delivery_bytes');
    $limited=dmo_integrity_media($db,1);
    ref_assert($limited['deliveries']['checked']===1 && !$limited['deliveries']['complete'],'bounded_scan_does_not_claim_full_coverage');
    $key=$db->query("SELECT storage_key FROM dmo_delivery_drafts WHERE id=".(int)$finalId)->fetchColumn();
    $file=dmo_delivery_file($key);$contents=file_get_contents($file);
    file_put_contents($file,'corrupted CI image');
    ref_assert(dmo_integrity_media($db)['deliveries']['invalid']===1,'integrity_detects_corrupt_private_file');
    file_put_contents($file,$contents);
    chmod(dirname($file),0755);clearstatcache(true);
    ref_assert(dmo_integrity_media($db)['deliveries']['invalid']===1,'integrity_detects_exposed_image_directory');
    chmod(dirname($file),0700);clearstatcache(true);
    ref_assert(dmo_integrity_media($db)['deliveries']['invalid']===0,'integrity_accepts_resecured_directory');
    // One uninterrupted journey through public HTTP endpoints using a verified
    // synthetic customer, not a seeded request, quote, order, payment or proof.
    $brief=['idempotency_key'=>'1d4c9c0e-1b63-4aa0-a2d4-f5d72b0bde24','tier_id'=>null,'addon_ids'=>[],'subject_type'=>'portrait','brief'=>'Synthetic end-to-end portrait with private reference and one revision.','preferred_contact'=>'onsite','preferred_deadline'=>null,'policy_version'=>'qa-policy-v1','policy_sha256'=>dmo_config('DMO_REQUEST_POLICY_SHA256'),'acknowledge_review'=>true];
    [$code,$created]=ref_http('/api/requests.php',$cookieB,$brief,$csrfB);
    ref_assert($code===201,'journey_customer_submits_new_request');
    $journeyRef=$created['public_ref'];
    $q=$db->prepare('SELECT id FROM dmo_commission_requests WHERE public_ref=:ref AND customer_id=:customer');
    $q->execute(['ref'=>$journeyRef,'customer'=>$customerB]);$journey=(int)$q->fetchColumn();
    ref_assert($journey>0,'journey_persisted_customer_owned_request');
    [$code,$refUpload]=ref_http('/api/request-attachment.php',$cookieB,null,$csrfB,['ref'=>$journeyRef,'path'=>$png,'type'=>'image/png']);
    ref_assert($code===201,'journey_upload_private_reference');
    [$code]=ref_http('/api/reference.php?id='.$refUpload['attachment_id'],$cookieA);
    ref_assert($code===404,'journey_reference_cross_account_denied');
    [$code,$draft]=ref_http('/api/admin/request-actions.php',$artistCookie,['action'=>'create_quote_draft','request_id'=>$journey,'currency'=>'CAD','scope'=>'Synthetic end-to-end portrait','revision_allowance'=>1,'lines'=>[['description'=>'Portrait','amount_minor'=>4200],['description'=>'Background','amount_minor'=>800]]],$artistCsrf);
    ref_assert($code===201&&$draft['total_minor']===5000,'journey_artist_itemizes_quote');
    $journeyQuote=$draft['quote_id'];
    [$code,$sent]=ref_http('/api/admin/quote-send.php',$artistCookie,['request_id'=>$journey,'quote_id'=>$journeyQuote,'validity_days'=>7],$artistCsrf);
    ref_assert($code===200&&$sent['payment_collected']===false,'journey_artist_sends_quote');
    [$code]=ref_http('/api/quote-response.php',$cookieB,['ref'=>$journeyRef,'quote_id'=>$journeyQuote,'decision'=>'accept','policy_version'=>'qa-policy-v1','acknowledge_quote'=>true],$csrfB);
    ref_assert($code===200,'journey_customer_accepts_quote');
    [$code,$confirmed]=ref_http('/api/admin/order-create.php',$artistCookie,['request_id'=>$journey,'accepted_quote_id'=>$journeyQuote,'confirm_unpaid'=>true],$artistCsrf);
    ref_assert($code===201&&$confirmed['payment_status']==='unverified','journey_artist_confirms_unpaid_order');
    $journeyOrder=$confirmed['order_id'];
    $invoice=['action'=>'issue_checkout_link','request_id'=>$journey,'order_id'=>$journeyOrder,'expected_link_id'=>null,'url'=>'https://checkout.example.invalid/invoice/journey-only','currency'=>'CAD','amount_minor'=>5000,'confirm_invoice'=>true];
    [$code,$published]=ref_http('/api/admin/request-actions.php',$artistCookie,$invoice,$artistCsrf);
    ref_assert($code===201,'journey_artist_issues_external_invoice');
    $journeyLink=$published['link_id'];
    [$code,$json,$bytes,$headers]=ref_http('/api/checkout.php?id='.$journeyLink,$cookieB);
    ref_assert($code===303&&str_contains($headers,'https://checkout.example.invalid/invoice/journey-only'),'journey_customer_redirects_to_synthetic_invoice_without_following');
    [$code,$state]=ref_http('/api/request.php?ref='.$journeyRef,$cookieB);
    ref_assert($code===200&&$state['orders'][0]['payment_status']==='unverified','journey_redirect_does_not_confirm_payment');
    $advanceJourney=['action'=>'set_production_status','request_id'=>$journey,'order_id'=>$journeyOrder,'expected_status'=>'awaiting_arrangement','status'=>'in_progress'];
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,$advanceJourney,$artistCsrf);
    ref_assert($code===409,'journey_unpaid_cannot_start');
    $evidence=['action'=>'record_payment_evidence','request_id'=>$journey,'order_id'=>$journeyOrder,'method'=>'bank_transfer','reference'=>'CI-JOURNEY-TRANSACTION-5000','currency'=>'CAD','amount_minor'=>5000];
    [$code,$pending]=ref_http('/api/admin/request-actions.php',$artistCookie,$evidence,$artistCsrf);
    ref_assert($code===201&&$pending['status']==='pending_verification','journey_artist_records_pending_evidence');
    [$code]=ref_http('/api/checkout.php?id='.$journeyLink,$cookieB);
    ref_assert($code===404,'journey_pending_evidence_removes_invoice');
    [$code,$paid]=ref_http('/api/admin/request-actions.php',$artistCookie,array_replace($evidence,['action'=>'verify_payment_evidence','payment_id'=>$pending['payment_id'],'confirm_received'=>true]),$artistCsrf);
    ref_assert($code===200&&$paid['payment_status']==='paid'&&$paid['money_moved']===false,'journey_full_manual_reconciliation');
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,['action'=>'revoke_checkout_link','request_id'=>$journey,'order_id'=>$journeyOrder,'expected_link_id'=>$journeyLink,'confirm_external_closed'=>true],$artistCsrf);
    ref_assert($code===200,'journey_external_invoice_closure_recorded');
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,$advanceJourney,$artistCsrf);
    ref_assert($code===200,'journey_artist_starts_production');
    $journeyUpload=['request_id'=>$journey,'order_id'=>$journeyOrder,'path'=>$png,'type'=>'image/png'];
    [$code,$draft]=ref_http('/api/admin/delivery-draft.php',$artistCookie,null,$artistCsrf,$journeyUpload);
    ref_assert($code===201,'journey_artist_stages_artwork');
    $journeyDraft=$draft['draft_id'];
    [$code,$proof]=ref_http('/api/admin/request-actions.php',$artistCookie,['action'=>'offer_artwork_review','request_id'=>$journey,'order_id'=>$journeyOrder,'draft_id'=>$journeyDraft],$artistCsrf);
    ref_assert($code===201,'journey_artist_offers_proof');
    [$code]=ref_http('/api/artwork-review.php',$cookieB,['review_id'=>$proof['review_id'],'decision'=>'approve','feedback'=>''],$csrfB);
    ref_assert($code===200,'journey_customer_approves_proof');
    [$code]=ref_http('/api/admin/request-actions.php',$artistCookie,array_replace($advanceJourney,['expected_status'=>'in_progress','status'=>'ready_for_delivery']),$artistCsrf);
    ref_assert($code===200,'journey_artist_marks_ready');
    [$code,$released]=ref_http('/api/admin/request-actions.php',$artistCookie,['action'=>'release_delivery','request_id'=>$journey,'order_id'=>$journeyOrder,'draft_id'=>$journeyDraft,'confirm_final'=>true],$artistCsrf);
    ref_assert($code===201,'journey_final_artwork_released');
    [$code,$json,$bytes,$headers]=ref_http('/api/delivery.php?id='.$released['delivery_id'],$cookieB);
    ref_assert($code===200&&substr($bytes,8,4)==='WEBP'&&str_contains(strtolower($headers),'no-store'),'journey_owner_downloads_final');
    [$code]=ref_http('/api/delivery.php?id='.$released['delivery_id'],$cookieA);
    ref_assert($code===404,'journey_cross_account_download_denied');
    [$code,$export]=ref_http('/api/auth/privacy.php',$cookieB,['action'=>'export','current_password'=>'Reference-only-CI-password-2026'],$csrfB);
    ref_assert($code===200&&count($export['export']['delivery_metadata'])===1&&!str_contains(json_encode($export,JSON_THROW_ON_ERROR),'journey-only'),'journey_export_contains_delivery_metadata_without_invoice_secret');
    ref_assert(array_sum(dmo_integrity_records($db))===0,'journey_final_database_integrity');
    echo "DISPOSABLE_COMMISSION_JOURNEY=PASS\n";
    echo "DISPOSABLE_DELIVERY_DRAFTS=PASS\n";
} catch(Throwable $error){
    fwrite(STDERR,"DISPOSABLE_DELIVERY_DRAFTS=FAIL ".$error->getMessage()."\n");exit(1);
}finally{
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if(is_string($log))@unlink($log);
    ref_clean($private);
}
