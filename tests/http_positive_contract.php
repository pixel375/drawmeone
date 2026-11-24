<?php
declare(strict_types=1);
/**
 * Full synthetic quote lifecycle through real HTTP endpoints:
 * admin creates draft -> releases quote -> customer accepts -> admin
 * confirms unpaid order; private messages and authorization checked too.
 *
 * This is NOT a production smoke test. The database name, user, localhost
 * and CI marker are checked before any database writes.
 */
if (PHP_SAPI !== 'cli' || getenv('DMO_CI_INTEGRATION') !== '1' ||
    getenv('DB_HOST') !== '127.0.0.1' ||
    getenv('DB_NAME') !== 'drawmeone_ci' ||
    getenv('DB_USER') !== 'ci_user') {
    fwrite(STDERR, "Disposable CI database required.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/app/auth.php';

function qa_positive(bool $condition, string $name): void
{
    if (!$condition) throw new RuntimeException('CI_POSITIVE_FAIL=' . $name);
    echo "PASS: " . $name . "\n";
}
function qa_seed(PDO $db,string $sql,array $args): int
{
    $db->prepare($sql)->execute($args);
    return (int)$db->lastInsertId();
}
function qa_http(string $path,?string $cookie=null,string $cookieName='__Host-dmo_session',
    ?array $data=null,?string $csrf=null): array
{
    $curl=curl_init('http://127.0.0.1:18862'.$path);
    if ($curl===false) throw new RuntimeException('Local HTTP client unavailable.');
    $headers=['Accept: application/json'];
    $args=[
        CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>6,CURLOPT_CONNECTTIMEOUT_MS=>2000,
        CURLOPT_FOLLOWLOCATION=>false,
    ];
    if ($cookie!==null) $args[CURLOPT_COOKIE]=$cookieName.'='.$cookie;
    if ($data!==null) {
        $args[CURLOPT_POST]=true;
        $args[CURLOPT_POSTFIELDS]=json_encode($data,JSON_THROW_ON_ERROR);
        $headers[]='Content-Type: application/json';
        $headers[]='Origin: https://drawmeone.com';
    }
    if ($csrf!==null) $headers[]='X-DMO-CSRF: '.$csrf;
    $args[CURLOPT_HTTPHEADER]=$headers;
    curl_setopt_array($curl,$args);
    $body=curl_exec($curl);
    $status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$status,is_string($body)?json_decode($body,true):null];
}

$process=null;
$log=tempnam(sys_get_temp_dir(),'dmo-positive-');
try {
    $db=dmo_database();
    $hash=password_hash('CI-positive-disposable-password!',PASSWORD_DEFAULT);
    $a=qa_seed($db,
        "INSERT INTO dmo_users (email,display_name,password_hash,role,account_status,email_verified_at)
         VALUES ('positive-a@example.invalid','Test A',:password,'customer','active',UTC_TIMESTAMP())",
        ['password'=>$hash]);
    $b=qa_seed($db,
        "INSERT INTO dmo_users (email,display_name,password_hash,role,account_status,email_verified_at)
         VALUES ('positive-b@example.invalid','Test B',:password,'customer','active',UTC_TIMESTAMP())",
        ['password'=>$hash]);
    $admin=qa_seed($db,
        "INSERT INTO dmo_users (email,display_name,password_hash,role,account_status,email_verified_at)
         VALUES ('positive-admin@example.invalid','QA Artist',:password,'admin','active',UTC_TIMESTAMP())",
        ['password'=>$hash]);
    $referenceA='DMO-'.substr(hash('sha256','positive-request-a'),0,20);
    $referenceB='DMO-'.substr(hash('sha256','positive-request-b'),0,20);
    $requestA=qa_seed($db,
        "INSERT INTO dmo_commission_requests
         (public_ref,customer_id,subject_type,brief,preferred_contact,policy_version,status)
         VALUES (:ref,:customer,'portrait',:brief,'onsite','qa-policy-v1','submitted')",
        ['ref'=>$referenceA,'customer'=>$a,
         'brief'=>str_repeat('A private test drawing.',3)]);
    $requestB=qa_seed($db,
        "INSERT INTO dmo_commission_requests
         (public_ref,customer_id,subject_type,brief,preferred_contact,policy_version,status)
         VALUES (:ref,:customer,'portrait',:brief,'onsite','qa-policy-v1','submitted')",
        ['ref'=>$referenceB,'customer'=>$b,
         'brief'=>str_repeat('B private test drawing.',3)]);
    $tokenA=bin2hex(random_bytes(32));
    $tokenB=bin2hex(random_bytes(32));
    $tokenAdmin=bin2hex(random_bytes(32));
    foreach ([[$a,$tokenA],[$b,$tokenB],[$admin,$tokenAdmin]] as [$user,$token]) {
        qa_seed($db,
            'INSERT INTO dmo_sessions (user_id,session_hash,expires_at,last_seen_at)
             VALUES (:user,:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),UTC_TIMESTAMP())',
            ['user'=>$user,'hash'=>hash('sha256',$token)]);
    }
    $key=dmo_config('DMO_APP_KEY');
    $csrfA=hash_hmac('sha256','dmo-csrf|'.hash('sha256',$tokenA),$key);
    $csrfB=hash_hmac('sha256','dmo-csrf|'.hash('sha256',$tokenB),$key);
    $csrfAdmin=hash_hmac('sha256','dmo-csrf|'.hash('sha256',$tokenAdmin),$key);

    // All fake business flags are scoped to the ephemeral local server.
    foreach ([
        'DMO_AUTH_ENABLED'=>'1','DMO_ADMIN_ENABLED'=>'1',
        'DMO_MAIL_ENABLED'=>'1','DMO_MAIL_TRANSPORT'=>'php',
        'DMO_ALLOW_PHP_MAIL'=>'1','DMO_MAIL_FROM'=>'support@drawmeone.com',
        'DMO_TURNSTILE_SITE_KEY'=>'ci-only-site-key',
        'DMO_TURNSTILE_SECRET'=>'ci-only-secret',
        'DMO_PRIVACY_PUBLISHED'=>'1','DMO_REQUEST_TERMS_APPROVED'=>'1',
        'DMO_REQUEST_ADMIN_WRITES_ENABLED'=>'1',
        'DMO_QUOTE_DELIVERY_ENABLED'=>'1','DMO_QUOTE_RESPONSES_ENABLED'=>'1',
        'DMO_CUSTOMER_MESSAGING_ENABLED'=>'1',
        'DMO_ORDER_ADMIN_ENABLED'=>'1','DMO_ORDER_TERMS_APPROVED'=>'1',
        'DMO_REGISTRATION_ENABLED'=>'0','DMO_REQUESTS_ENABLED'=>'0',
    ] as $name=>$value) putenv($name.'='.$value);
    if (!is_string($log)) throw new RuntimeException('Local server log unavailable.');
    $process=proc_open([
        PHP_BINARY,'-S','127.0.0.1:18862','-t',
        dirname(__DIR__),__DIR__.'/ci_router.php',
    ],[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file',$log,'w']],$pipes);
    if (!is_resource($process)) throw new RuntimeException('Local server failed to start.');
    if(isset($pipes[0])) fclose($pipes[0]);
    $ready=false;
    for($i=0;$i<35;$i++){
        [$status]=qa_http('/api/request.php?ref='.urlencode($referenceA));
        if($status!==0){$ready=true;break;}
        usleep(100000);
    }
    qa_positive($ready,'local_positive_server_ready');
    // Catalog edits must never change a previously submitted commission label.
    $tierId=qa_seed($db,
        "INSERT INTO dmo_commission_tiers
         (slug,name,description,currency,sort_order,is_active)
         VALUES ('ci-historical-tier','Renamed tier','CI fixture','CAD',0,0)",[]);
    $addonId=qa_seed($db,
        "INSERT INTO dmo_addons
         (slug,name,description,currency,sort_order,is_active)
         VALUES ('ci-historical-addon','Renamed addon','CI fixture','CAD',0,0)",[]);
    $db->prepare('UPDATE dmo_commission_requests SET tier_id=:tier,tier_snapshot_json=:snapshot WHERE id=:id')
        ->execute(['tier'=>$tierId,'id'=>$requestA,'snapshot'=>json_encode([
            'id'=>$tierId,'name'=>'Original tier name','currency'=>'CAD',
            'estimate_min_minor'=>1000,'private_test_marker'=>'never-expose-snapshot'
        ],JSON_THROW_ON_ERROR)]);
    $db->prepare('INSERT INTO dmo_request_addons (request_id,addon_id,addon_snapshot_json) VALUES (:request,:addon,:snapshot)')
        ->execute(['request'=>$requestA,'addon'=>$addonId,'snapshot'=>json_encode([
            'id'=>$addonId,'name'=>'Original addon name','private_test_marker'=>'never-expose-snapshot'
        ],JSON_THROW_ON_ERROR)]);
    [$code,$historic]=qa_http('/api/request.php?ref='.urlencode($referenceA),$tokenA);
    qa_positive($code===200 && $historic['request']['tier_name']==='Original tier name' &&
        $historic['addons']===['Original addon name'] &&
        !str_contains(json_encode($historic,JSON_THROW_ON_ERROR),'never-expose-snapshot'),
        'customer_detail_preserves_catalog_snapshot_labels');
    [$code,$historic]=qa_http('/api/requests.php',$tokenA);
    qa_positive($code===200 && count($historic['requests'])===1 &&
        $historic['requests'][0]['tier_name']==='Original tier name' &&
        !isset($historic['requests'][0]['tier_snapshot_json']),
        'customer_history_preserves_snapshot_and_ownership');
    [$code,$historic]=qa_http('/api/admin/request.php?id='.$requestA,$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200 && $historic['detail']['request']['tier_name']==='Original tier name' &&
        $historic['detail']['addons']===[['name'=>'Original addon name']] &&
        !str_contains(json_encode($historic,JSON_THROW_ON_ERROR),'never-expose-snapshot'),
        'artist_detail_preserves_catalog_snapshot_labels');
    $db->prepare('UPDATE dmo_commission_requests SET tier_id=:tier,tier_snapshot_json=NULL WHERE id=:id')
        ->execute(['tier'=>$tierId,'id'=>$requestB]);
    [$code,$legacy]=qa_http('/api/request.php?ref='.urlencode($referenceB),$tokenB);
    qa_positive($code===200 && $legacy['request']['tier_name']==='Renamed tier',
        'legacy_request_uses_current_label_when_snapshot_missing');
    [$code]=qa_http('/api/admin/requests.php');
    qa_positive($code===401,'anonymous_admin_request_search_denied');
    [$code]=qa_http('/api/admin/requests.php',$tokenA,'__Host-dmo_admin');
    qa_positive(in_array($code,[401,403],true),'customer_admin_request_search_denied');
    [$code,$found]=qa_http('/api/admin/requests.php?q='.urlencode($referenceA),
        $tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200 && count($found['requests']??[])===1 &&
        $found['requests'][0]['public_ref']===$referenceA &&
        $found['requests'][0]['tier_name']==='Original tier name' &&
        !isset($found['requests'][0]['tier_snapshot_json']),'admin_search_reference_and_historical_label');
    [$code,$found]=qa_http('/api/admin/requests.php?q=Test%20B&filter=review',
        $tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200 && count($found['requests']??[])===1 &&
        $found['requests'][0]['public_ref']===$referenceB,'admin_search_name_and_review_filter');
    [$code,$found]=qa_http('/api/admin/requests.php?q=%25',
        $tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200 && $found['requests']===[],'admin_search_wildcard_is_literal');
    [$code]=qa_http('/api/admin/requests.php?page=-1',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===422,'invalid_admin_list_page_rejected');
    $extraIds=[];
    for($i=0;$i<41;$i++){
        $extraIds[]=qa_seed($db,
            "INSERT INTO dmo_commission_requests
             (public_ref,customer_id,subject_type,brief,preferred_contact,policy_version,status)
             VALUES (:ref,:customer,'portrait','Pagination test','onsite','qa-policy-v1','submitted')",
            ['ref'=>'DMO-'.substr(hash('sha256','page-fixture-'.$i),0,20),'customer'=>$a]);
    }
    [$code,$first]=qa_http('/api/admin/requests.php?q=Test%20A&page=1',
        $tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200 && count($first['requests'])===40 && $first['has_more']===true,
        'admin_list_first_page_bounded');
    [$code,$second]=qa_http('/api/admin/requests.php?q=Test%20A&page=2',
        $tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200 && count($second['requests'])===2 && $second['has_more']===false &&
        array_intersect(array_column($first['requests'],'id'),array_column($second['requests'],'id'))===[],
        'admin_list_next_page_no_duplicates');
    $delete=$db->prepare('DELETE FROM dmo_commission_requests WHERE id=:id');
    foreach($extraIds as $extraId)$delete->execute(['id'=>$extraId]);

    [$code,$initial]=qa_http('/api/request.php?ref='.urlencode($referenceA),$tokenA);
    qa_positive($code===200 && ($initial['quotes']??[])===[],
        'draft_not_visible_before_artist_release');

    [$code,$draft]=qa_http('/api/admin/request-actions.php',$tokenAdmin,
        '__Host-dmo_admin',[
            'action'=>'create_quote_draft','request_id'=>$requestA,
            'currency'=>'CAD','scope'=>'Synthetic original portrait, no external rights',
            'revision_allowance'=>2,'lines'=>[
                ['description'=>'Illustration','amount_minor'=>2700],
                ['description'=>'Background','amount_minor'=>300],
            ],
        ],$csrfAdmin);
    qa_positive($code===201 && ($draft['status']??'')==='draft' &&
        ($draft['total_minor']??0)===3000 &&
        is_int($draft['quote_id']??null),'artist_creates_exact_price_draft');
    $quoteId=$draft['quote_id'];
    [$code,$unreleased]=qa_http('/api/request.php?ref='.urlencode($referenceA),$tokenA);
    qa_positive($code===200 && ($unreleased['quotes']??[])===[],
        'new_draft_still_hidden');

    [$code,$release]=qa_http('/api/admin/quote-send.php',$tokenAdmin,
        '__Host-dmo_admin',[
            'request_id'=>$requestA,'quote_id'=>$quoteId,'validity_days'=>7,
        ],$csrfAdmin);
    qa_positive($code===200 && ($release['status']??'')==='sent' &&
        ($release['payment_collected']??true)===false,
        'artist_releases_quote_without_payment');
    [$code,$offer]=qa_http('/api/request.php?ref='.urlencode($referenceA),$tokenA);
    qa_positive($code===200 && count($offer['quotes']??[])===1 &&
        ($offer['quotes'][0]['id']??null)===$quoteId &&
        ($offer['quotes'][0]['can_respond']??false)===true &&
        count($offer['quotes'][0]['lines']??[])===2,
        'customer_receives_itemized_live_offer');

    [$code]=qa_http('/api/quote-response.php',$tokenB,
        '__Host-dmo_session',[
            'ref'=>$referenceA,'quote_id'=>$quoteId,'decision'=>'accept',
            'policy_version'=>'qa-policy-v1','acknowledge_quote'=>true,
        ],$csrfB);
    qa_positive($code===404,'other_customer_cannot_accept_quote');
    [$code]=qa_http('/api/quote-response.php',$tokenA,
        '__Host-dmo_session',[
            'ref'=>$referenceA,'quote_id'=>$quoteId,'decision'=>'accept',
            'policy_version'=>'qa-policy-v1','acknowledge_quote'=>true,
            'total_minor'=>1,
        ],$csrfA);
    qa_positive($code===422,'client_price_override_rejected');

    [$code,$accept]=qa_http('/api/quote-response.php',$tokenA,
        '__Host-dmo_session',[
            'ref'=>$referenceA,'quote_id'=>$quoteId,'decision'=>'accept',
            'policy_version'=>'qa-policy-v1','acknowledge_quote'=>true,
        ],$csrfA);
    qa_positive($code===200 && ($accept['status']??'')==='accepted' &&
        ($accept['payment_collected']??true)===false,
        'customer_accepts_exact_uncharged_quote');
    [$code]=qa_http('/api/quote-response.php',$tokenA,
        '__Host-dmo_session',[
            'ref'=>$referenceA,'quote_id'=>$quoteId,'decision'=>'accept',
            'policy_version'=>'qa-policy-v1','acknowledge_quote'=>true,
        ],$csrfA);
    qa_positive($code===409,'accepted_quote_replay_rejected');

    [$code,$order]=qa_http('/api/admin/order-create.php',$tokenAdmin,
        '__Host-dmo_admin',[
            'request_id'=>$requestA,'accepted_quote_id'=>$quoteId,
            'confirm_unpaid'=>true,
        ],$csrfAdmin);
    qa_positive($code===201 && ($order['payment_status']??'')==='unverified' &&
        ($order['payment_collected']??true)===false,
        'artist_confirms_unpaid_order');
    [$code]=qa_http('/api/admin/order-create.php',$tokenAdmin,
        '__Host-dmo_admin',[
            'request_id'=>$requestA,'accepted_quote_id'=>$quoteId,
            'confirm_unpaid'=>true,
        ],$csrfAdmin);
    qa_positive($code===409,'duplicate_order_confirmation_rejected');

    [$code,$overview]=qa_http('/api/admin/overview.php',
        $tokenAdmin,'__Host-dmo_admin');
    if (!($code===200 &&
        ($overview['counts']['active_orders']??null)===1 &&
        ($overview['counts']['payments_to_verify']??null)===0)) {
        // Only fixed status/counters. Never print customer details or private payloads.
        echo "CI_OVERVIEW_HTTP=" . $code . "\\n";
        echo "CI_OVERVIEW_ACTIVE=" .
            json_encode($overview['counts']['active_orders']??null) . "\\n";
        echo "CI_OVERVIEW_PAYMENTS=" .
            json_encode($overview['counts']['payments_to_verify']??null) . "\\n";
        echo "CI_OVERVIEW_ERROR=" .
            json_encode($overview['error']??null) . "\\n";
    }
    qa_positive($code===200 &&
        ($overview['counts']['active_orders']??null)===1 &&
        ($overview['counts']['payments_to_verify']??null)===0,
        'artist_dashboard_counts_unpaid_order_without_fake_payment');

    [$code,$customerDetail]=qa_http('/api/request.php?ref='.urlencode($referenceA),$tokenA);
    qa_positive($code===200 && count($customerDetail['orders']??[])===1 &&
        ($customerDetail['orders'][0]['payment_status']??'')==='unverified' &&
        ($customerDetail['orders'][0]['total_minor']??null)===3000,
        'customer_sees_correct_unpaid_order');
    [$code,$otherDetail]=qa_http('/api/request.php?ref='.urlencode($referenceB),$tokenB);
    qa_positive($code===200 && ($otherDetail['quotes']??[])===[] &&
        ($otherDetail['orders']??[])===[],
        'other_customer_not_exposed_to_order');

    [$code,$sentMessage]=qa_http('/api/request-message.php',$tokenA,
        '__Host-dmo_session',[
            'ref'=>$referenceA,'body'=>'Please use soft shading.',
        ],$csrfA);
    qa_positive($code===201,'customer_can_send_private_message');
    [$code]=qa_http('/api/request-message.php',$tokenB,
        '__Host-dmo_session',[
            'ref'=>$referenceA,'body'=>'Unauthorized message.',
        ],$csrfB);
    qa_positive($code===404,'other_customer_cannot_message_request');
    [$code,$privateNote]=qa_http('/api/admin/request-actions.php',
        $tokenAdmin,'__Host-dmo_admin',[
            'action'=>'add_private_note','request_id'=>$requestA,
            'body'=>'CI ARTIST-ONLY PRIVATE NOTE',
        ],$csrfAdmin);
    qa_positive($code===201 && ($privateNote['visibility']??'')==='internal_only',
        'artist_private_note_saved_as_internal');
    [$code,$publicReply]=qa_http('/api/admin/request-actions.php',
        $tokenAdmin,'__Host-dmo_admin',[
            'action'=>'send_customer_message','request_id'=>$requestA,
            'body'=>'Understood, soft shading it is.',
        ],$csrfAdmin);
    qa_positive($code===201 && ($publicReply['visibility']??'')==='customer_visible',
        'artist_public_reply_saved_separately');
    [$code,$thread]=qa_http('/api/request.php?ref='.urlencode($referenceA),$tokenA);
    qa_positive($code===200 && count($thread['messages']??[])===2 &&
        !str_contains(json_encode($thread,JSON_THROW_ON_ERROR),
            'CI ARTIST-ONLY PRIVATE NOTE'),
        'internal_note_excluded_from_real_http_response');

    $db->prepare("UPDATE dmo_commission_requests SET status='completed' WHERE id=:id")
       ->execute(['id'=>$requestA]);
    [$code]=qa_http('/api/request-message.php',$tokenA,
        '__Host-dmo_session',[
            'ref'=>$referenceA,'body'=>'Should be blocked after completion.',
        ],$csrfA);
    qa_positive($code===409,'closed_request_rejects_new_messages');
    qa_positive((int)$db->query('SELECT COUNT(*) FROM dmo_payments')->fetchColumn()===0,
        'full_positive_lifecycle_never_creates_payment');
    echo "DISPOSABLE_POSITIVE_HTTP=PASS\n";
} catch(Throwable $error) {
    fwrite(STDERR,'DISPOSABLE_POSITIVE_HTTP=FAIL ('.get_class($error).
        ': '.$error->getMessage().")\n");
    exit(1);
} finally {
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    if(is_string($log)) @unlink($log);
}
