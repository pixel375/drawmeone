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
require_once dirname(__DIR__) . '/app/integrity.php';

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
    return [$status,is_string($body)?json_decode($body,true):null,is_string($body)?$body:""];
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
    $oldPin=hash_file('sha256',__DIR__.'/fixtures/policies/qa-policy-v0.json');
    $db->prepare("UPDATE dmo_commission_requests SET policy_version='qa-policy-v0' WHERE id=:id")->execute(['id'=>$requestA]);
    $receipt=$db->prepare("INSERT INTO dmo_audit_log (actor_id,action,entity_type,entity_id,details_json) VALUES (:actor,'request.submitted','commission_request',:id,:details)");
    $receipt->execute(['actor'=>$a,'id'=>$requestA,'details'=>json_encode(['policy_version'=>'qa-policy-v0','policy_sha256'=>$oldPin],JSON_THROW_ON_ERROR)]);
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
        'DMO_AUTH_ENABLED'=>'1','DMO_ADMIN_ENABLED'=>'1','DMO_DESIGN_WRITES_ENABLED'=>'1','DMO_PUBLIC_DESIGN_ENABLED'=>'1','DMO_PACKAGE_WRITES_ENABLED'=>'1','DMO_PACKAGE_PUBLISH_ENABLED'=>'1','DMO_PRICING_APPROVED'=>'1',
        'DMO_MAIL_ENABLED'=>'1','DMO_MAIL_TRANSPORT'=>'php',
        'DMO_ALLOW_PHP_MAIL'=>'1','DMO_MAIL_FROM'=>'support@drawmeone.com',
        'DMO_TURNSTILE_SITE_KEY'=>'ci-only-site-key',
        'DMO_TURNSTILE_SECRET'=>'ci-only-secret',
        'DMO_PRIVACY_PUBLISHED'=>'1','DMO_REQUEST_TERMS_APPROVED'=>'1',
        'DMO_REQUEST_ADMIN_WRITES_ENABLED'=>'1',
        'DMO_QUOTE_DELIVERY_ENABLED'=>'1','DMO_QUOTE_RESPONSES_ENABLED'=>'1',
        'DMO_CUSTOMER_MESSAGING_ENABLED'=>'1',
        'DMO_ORDER_ADMIN_ENABLED'=>'1','DMO_ORDER_TERMS_APPROVED'=>'1',
        'DMO_MANUAL_PAYMENT_ENABLED'=>'1','DMO_MANUAL_PAYMENT_TERMS_APPROVED'=>'1',
        'DMO_REGISTRATION_ENABLED'=>'0','DMO_REQUESTS_ENABLED'=>'1','DMO_INTAKE_CONTROL_ENABLED'=>'1','DMO_CANCELLATION_ENABLED'=>'1','DMO_CANCELLATION_TERMS_APPROVED'=>'1','DMO_CHECKOUT_LINKS_ENABLED'=>'1','DMO_CHECKOUT_TERMS_APPROVED'=>'1','DMO_CHECKOUT_HOST'=>'checkout.example.invalid','DMO_CHECKOUT_PATH_PREFIX'=>'/invoice/','DMO_PRIVACY_SELF_SERVICE_ENABLED'=>'1',
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
    [$code]=qa_http('/api/admin/customers.php',$tokenA,'__Host-dmo_admin');
    qa_positive(in_array($code,[401,403],true),'customer_directory_admin_only');
    [$code,$directory]=qa_http('/api/admin/customers.php?state=verified',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&count($directory['customers'])===2&&!in_array($admin,array_column($directory['customers'],'id'),true),'customer_directory_excludes_admin');
    [$code,$directory]=qa_http('/api/admin/customers.php?q='.urlencode($referenceA),$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&count($directory['customers'])===1&&$directory['customers'][0]['id']===$a&&$directory['customers'][0]['request_count']===1,'customer_lookup_by_commission_reference');
    qa_positive(!str_contains(json_encode($directory,JSON_THROW_ON_ERROR),'password_hash')&&!str_contains(json_encode($directory,JSON_THROW_ON_ERROR),'session_hash'),'customer_directory_no_credentials');
    [$code,$history]=qa_http('/api/admin/requests.php?customer_id='.$a,$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&count($history['requests'])===1&&$history['requests'][0]['id']===$requestA&&$history['matching_count']===1,'customer_history_exact_id_scope');
    [$code,$emptyPage]=qa_http('/api/admin/requests.php?customer_id='.$a.'&page=2',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$emptyPage['requests']===[]&&$emptyPage['matching_count']===1,'artist_request_total_independent_of_page');
    [$code,$history]=qa_http('/api/admin/requests.php?customer_id='.$admin,$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$history['requests']===[],'customer_history_excludes_admin');
    [$code,$matches]=qa_http('/api/admin/requests.php?scope=brief&q='.urlencode('A private test drawing').'&sort=oldest',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&array_column($matches['requests'],'id')===[$requestA],'artist_brief_search');
    [$code,$matches]=qa_http('/api/admin/requests.php?scope=package&q=portrait&sort=oldest',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&array_column($matches['requests'],'id')===[$requestA,$requestB],'artist_subject_search_and_oldest');
    [$code]=qa_http('/api/admin/requests.php?scope=brief&q=portrait',$tokenA);
    qa_positive(in_array($code,[401,403],true),'private_brief_search_artist_only');
    [$code]=qa_http('/api/admin/requests.php?scope=invalid',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===422,'invalid_artist_search_scope');
    [$code]=qa_http('/api/admin/customers.php?state=invalid',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===422,'customer_directory_invalid_filter_denied');
    [$code,$directory]=qa_http('/api/admin/customers.php?history=with_requests',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$directory['matching_count']===2&&count($directory['customers'])===2,'customer_directory_with_history_count');
    [$code,$directory]=qa_http('/api/admin/customers.php?history=without_requests',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$directory['matching_count']===0&&$directory['customers']===[],'customer_directory_no_history_filter');
    [$code]=qa_http('/api/admin/customers.php?history=invalid',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===422,'customer_directory_invalid_history_filter');
    [$code,$archived]=qa_http('/api/policy.php?version=qa-policy-v0&sha256='.$oldPin);
    qa_positive($code===200&&$archived['policy']['version']==='qa-policy-v0','explicitly_pinned_historical_policy_readable');
    [$code,,$policyHtml]=qa_http('/policy.php?version=qa-policy-v0&sha256='.$oldPin);
    qa_positive($code===200&&str_contains($policyHtml,htmlspecialchars($archived['policy']['terms'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'))&&str_contains($policyHtml,'Permanent version link'),'policy_html_exact_archived_text');
    [$code,,$policyHtml]=qa_http('/policy.php?version=qa-policy-v0&sha256='.str_repeat('0',64));
    qa_positive($code===404&&!str_contains($policyHtml,htmlspecialchars($archived['policy']['terms'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')),'policy_html_wrong_pin_denied');
    [$code,$currentPolicy]=qa_http('/api/policy.php');
    [$htmlCode,,$policyHtml]=qa_http('/policy.php');
    qa_positive($code===200&&$htmlCode===200&&str_contains($policyHtml,htmlspecialchars($currentPolicy['policy']['privacy'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')),'policy_html_current_matches_api');
    [$code]=qa_http('/api/policy.php?version=qa-policy-v0&sha256='.str_repeat('0',64));
    qa_positive($code===404,'historical_document_wrong_digest_denied');
    qa_positive(dmo_policy_request_eligible($db,$requestA,'qa-policy-v0'),'archived_receipt_matches_request_version');
    qa_positive(!dmo_policy_request_eligible($db,$requestA,'qa-policy-v1'),'receipt_version_mismatch_fails_closed');
    $pins=getenv('DMO_HISTORICAL_POLICY_PINS');
    putenv('DMO_HISTORICAL_POLICY_PINS={}');
    qa_positive(!dmo_policy_request_eligible($db,$requestA,'qa-policy-v0'),'historical_pin_required');
    putenv('DMO_HISTORICAL_POLICY_PINS='.$pins);
    $receipt->execute(['actor'=>$b,'id'=>$requestB,'details'=>json_encode(['policy_version'=>'qa-policy-v1','policy_sha256'=>'invalid-digest'],JSON_THROW_ON_ERROR)]);
    qa_positive(!dmo_policy_request_eligible($db,$requestB,'qa-policy-v1'),'malformed_current_receipt_not_legacy');
    $db->prepare("DELETE FROM dmo_audit_log WHERE entity_type='commission_request' AND entity_id=:id AND action='request.submitted'")->execute(['id'=>$requestB]);
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
    [$code,$first]=qa_http('/api/requests.php?page=1',$tokenA);
    qa_positive($code===200 && count($first['requests'])===40 &&
        $first['has_more']===true && $first['page']===1,'customer_history_first_page');
    [$code,$second]=qa_http('/api/requests.php?page=2',$tokenA);
    qa_positive($code===200 && count($second['requests'])===2 && $second['has_more']===false &&
        array_intersect(array_column($first['requests'],'public_ref'),array_column($second['requests'],'public_ref'))===[] &&
        !in_array($referenceB,array_column(array_merge($first['requests'],$second['requests']),'public_ref'),true),
        'customer_history_pages_no_overlap_or_other_customer');
    [$code,$found]=qa_http('/api/requests.php?q='.urlencode($referenceA).'&status=active&sort=oldest',$tokenA);
    qa_positive($code===200&&array_column($found['requests'],'public_ref')===[$referenceA]&&!isset($found['requests'][0]['history_id']),'full_history_finds_older_owned_reference');
    [$code,$found]=qa_http('/api/requests.php?q='.urlencode($referenceA),$tokenB);
    qa_positive($code===200&&$found['requests']===[],'full_history_search_cross_account_denied');
    [$code,$displayMatches]=qa_http('/api/requests.php?q='.urlencode('Awaiting artist review').'&status=active&page=1',$tokenA);
    qa_positive($code===200&&count($displayMatches['requests'])===40&&$displayMatches['has_more']===true&&
        count(array_filter($displayMatches['requests'],fn($row)=>$row['workflow_stage']!=='submitted'))===0,'full_history_matches_display_status_before_pagination');
    [$code,$privateMatches]=qa_http('/api/requests.php?q='.urlencode('Pagination test'),$tokenA);
    qa_positive($code===200&&$privateMatches['requests']===[],'full_history_search_does_not_match_private_brief');
    [$code,$found]=qa_http('/api/requests.php?status=closed',$tokenA);
    qa_positive($code===200&&$found['requests']===[],'full_history_closed_filter_excludes_active');
    [$code,$found]=qa_http('/api/requests.php?sort=oldest',$tokenA);
    qa_positive($code===200&&$found['requests'][0]['public_ref']===$referenceA,'full_history_oldest_order_applied_before_pagination');
    [$code]=qa_http('/api/requests.php?sort=invalid',$tokenA);
    qa_positive($code===422,'invalid_history_sort_rejected');
    [$code,$other]=qa_http('/api/requests.php?page=2',$tokenB);
    qa_positive($code===200 && $other['requests']===[],'other_customer_cannot_page_into_first_customer_history');
    [$code]=qa_http('/api/requests.php?page=0',$tokenA);
    qa_positive($code===422,'invalid_customer_history_page');
    [$code]=qa_http('/api/requests.php?page=2');
    qa_positive($code===401,'anonymous_history_page_denied');
    $delete=$db->prepare('DELETE FROM dmo_commission_requests WHERE id=:id');
    foreach($extraIds as $extraId)$delete->execute(['id'=>$extraId]);

    [$code,$initial]=qa_http('/api/request.php?ref='.urlencode($referenceA),$tokenA);
    qa_positive($code===200 && ($initial['quotes']??[])===[],
        'draft_not_visible_before_artist_release');

    [$code,$status]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',[
        'action'=>'set_review_status','request_id'=>$requestB,'expected_status'=>'submitted','status'=>'clarification_needed'
    ],$csrfAdmin);
    qa_positive($code===200 && $status['status']==='clarification_needed','artist_marks_clarification_needed');
    [$code,$clarification]=qa_http('/api/requests.php',$tokenB);
    qa_positive($code===200&&in_array($referenceB,$clarification['attention']['clarifications'],true),'customer_clarification_shortcut');
    [$code,$clarification]=qa_http('/api/requests.php',$tokenA);
    qa_positive($code===200&&!in_array($referenceB,$clarification['attention']['clarifications'],true),'clarification_shortcut_isolated');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',[
        'action'=>'set_review_status','request_id'=>$requestB,'expected_status'=>'submitted','status'=>'rejected'
    ],$csrfAdmin);
    qa_positive($code===409,'stale_review_status_conflict');
    [$code,$status]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',[
        'action'=>'set_review_status','request_id'=>$requestB,'expected_status'=>'clarification_needed','status'=>'submitted'
    ],$csrfAdmin);
    qa_positive($code===200 && $status['status']==='submitted','artist_returns_request_to_review');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',[
        'action'=>'set_review_status','request_id'=>$requestB,'expected_status'=>'submitted','status'=>'rejected'
    ],$csrfAdmin);
    qa_positive($code===200,'artist_declines_unquoted_request');
    [$code,$status]=qa_http('/api/request.php?ref='.urlencode($referenceB),$tokenB);
    qa_positive($code===200 && $status['request']['status']==='rejected' && $status['can_reply']===false,'declined_request_readable');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',[
        'action'=>'create_quote_draft','request_id'=>$requestB,'currency'=>'CAD','scope'=>'Closed synthetic request',
        'revision_allowance'=>0,'lines'=>[['description'=>'Portrait','amount_minor'=>1000]]
    ],$csrfAdmin);
    qa_positive($code===409,'closed_request_cannot_receive_quote_draft');

    [$code,$draft]=qa_http('/api/admin/request-actions.php',$tokenAdmin,
        '__Host-dmo_admin',[
            'action'=>'create_quote_draft','request_id'=>$requestA,
            'currency'=>'CAD','scope'=>'Synthetic original portrait, no external rights','operation_key'=>'10000000-0000-4000-8000-000000000006',
            'revision_allowance'=>2,'lines'=>[
                ['description'=>'Illustration','amount_minor'=>2700],
                ['description'=>'Background','amount_minor'=>300],
            ],
        ],$csrfAdmin);
    qa_positive($code===201 && ($draft['status']??'')==='draft' &&
        ($draft['total_minor']??0)===3000 &&
        is_int($draft['quote_id']??null),'artist_creates_exact_price_draft');
    $quoteId=$draft['quote_id'];
    $retryQuote=['action'=>'create_quote_draft','request_id'=>$requestA,'currency'=>'CAD','scope'=>'Synthetic original portrait, no external rights','operation_key'=>'10000000-0000-4000-8000-000000000006','revision_allowance'=>2,'lines'=>[['description'=>'Illustration','amount_minor'=>2700],['description'=>'Background','amount_minor'=>300]]];
    [$code,$replay]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',$retryQuote,$csrfAdmin);
    qa_positive($code===200&&($replay['quote_id']??null)===$quoteId&&($replay['already_saved']??false),'quote_retry_recovers_original_version');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',array_replace($retryQuote,['revision_allowance'=>3]),$csrfAdmin);
    qa_positive($code===409,'changed_quote_operation_conflict');
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
    [$code,$history]=qa_http('/api/requests.php',$tokenA);
    qa_positive($code===200&&in_array($referenceA,$history['attention']['quotes'],true),'customer_attention_contains_live_quote');
    [$code,$history]=qa_http('/api/requests.php',$tokenB);
    qa_positive($code===200&&!in_array($referenceA,$history['attention']['quotes'],true),'customer_attention_cross_account_denied');

    $notice=['action'=>'send_notification','request_id'=>$requestA,'kind'=>'quote','event_id'=>$quoteId,'confirm_send'=>true];
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',$notice,$csrfAdmin);
    qa_positive($code===503,'customer_notifications_default_disabled');
    require_once dirname(__DIR__).'/app/notifications.php';
    qa_positive(dmo_notification_target($db,$requestA,'quote',$quoteId)===null,
        'onsite_contact_preference_prevents_email');
    $db->prepare("UPDATE dmo_commission_requests SET preferred_contact='email' WHERE id=:id")->execute(['id'=>$requestA]);
    $reservation=dmo_notification_reserve($db,$admin,$requestA,'quote',$quoteId);
    qa_positive($reservation['status']==='reserved' && $reservation['recipient']==='positive-a@example.invalid',
        'notice_reserves_verified_customer_only');
    qa_positive(dmo_notification_reserve($db,$admin,$requestA,'quote',$quoteId)['status']==='duplicate',
        'uncertain_notice_blocks_duplicate');
    dmo_notification_finish($db,$admin,$requestA,'quote',$quoteId,true);
    qa_positive(dmo_notification_status($db,$requestA,'quote',$quoteId)==='accepted',
        'smtp_acceptance_ledger_without_real_mail');
    qa_positive(dmo_notification_reserve($db,$admin,$requestB,'quote',$quoteId)['status']==='ineligible',
        'cross_request_notice_rejected');
    $db->prepare("UPDATE dmo_commission_requests SET preferred_contact='onsite' WHERE id=:id")->execute(['id'=>$requestA]);
    // A private artist note never qualifies for a customer email.
    $db->prepare("UPDATE dmo_commission_requests SET preferred_contact='email' WHERE id=:id")->execute(['id'=>$requestA]);
    $q=$db->prepare("SELECT id FROM dmo_conversations WHERE request_id=:id");$q->execute(['id'=>$requestA]);$conv=(int)$q->fetchColumn();
    if(!$conv){$conv=qa_seed($db,"INSERT INTO dmo_conversations (request_id) VALUES (:id)",['id'=>$requestA]);}
    $messageId=qa_seed($db,"INSERT INTO dmo_messages (conversation_id,sender_id,body,is_internal) VALUES (:conv,:sender,'Synthetic notice message',0)",['conv'=>$conv,'sender'=>$admin]);
    $internalId=qa_seed($db,"INSERT INTO dmo_messages (conversation_id,sender_id,body,is_internal) VALUES (:conv,:sender,'Synthetic internal note',1)",['conv'=>$conv,'sender'=>$admin]);
    qa_positive(dmo_notification_target($db,$requestA,'message',$messageId)!==null,'latest_public_artist_message_notice');
    qa_positive(dmo_notification_target($db,$requestA,'message',$internalId)===null,'internal_note_never_notified');
    [$code,$replyAttention]=qa_http('/api/requests.php',$tokenA);
    qa_positive($code===200&&in_array($referenceA,$replyAttention['attention']['artist_replies'],true),'latest_artist_reply_shortcut_ignores_private_note');
    [$code,$replyAttention]=qa_http('/api/requests.php',$tokenB);
    qa_positive($code===200&&!in_array($referenceA,$replyAttention['attention']['artist_replies'],true),'artist_reply_shortcut_isolated');
    $replyId=qa_seed($db,"INSERT INTO dmo_messages (conversation_id,sender_id,body,is_internal) VALUES (:conv,:sender,'Synthetic customer response',0)",['conv'=>$conv,'sender'=>$a]);
    qa_positive(dmo_notification_target($db,$requestA,'message',$messageId)===null,'customer_reply_invalidates_message_notice');
    [$code,$replyAttention]=qa_http('/api/requests.php',$tokenA);
    qa_positive($code===200&&!in_array($referenceA,$replyAttention['attention']['artist_replies'],true),'customer_response_clears_recent_artist_reply_shortcut');
    [$code,$queue]=qa_http('/api/admin/requests.php?filter=reply_due',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&in_array($requestA,array_map('intval',array_column($queue['requests'],'id')),true),'reply_due_queue_customer_latest');
    [$code,$inbox]=qa_http('/api/admin/messages.php?filter=reply_due',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&in_array($replyId,array_map('intval',array_column($inbox['messages'],'id')),true),'reply_due_inbox_latest_only');
    [$code,$overview]=qa_http('/api/admin/overview.php',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$overview['counts']['reply_due']===count($queue['requests']),'reply_due_count_matches_queue');
    $checks=array_column($overview['readiness'],null,'key');
    qa_positive(isset($checks['image_processor'])&&in_array($checks['image_processor']['status'],['ready','blocked'],true),'image_processing_readiness_present');
    qa_positive($overview['launch_evidence']['authorizes_launch']===false,'runtime_report_never_authorizes_launch');
    $opening=array_column($overview['launch_evidence']['stages'],null,'key')['opening'];
    foreach(['pricing','mail','accounts','configuration_review','image_processor'] as $required){
        if($checks[$required]['status']!=='ready')qa_positive(in_array($checks[$required]['label'],$opening['configuration_pending'],true),'opening_includes_'.$required);
    }
    $db->prepare("UPDATE dmo_commission_requests SET status='in_progress' WHERE id=:id")->execute(['id'=>$requestA]);
    [$code,$productionQueue]=qa_http('/api/admin/requests.php?filter=reply_due',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&in_array($requestA,array_map('intval',array_column($productionQueue['requests'],'id')),true),'production_request_reply_due_visible');
    [$code,$productionInbox]=qa_http('/api/admin/messages.php?filter=reply_due',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&in_array($replyId,array_map('intval',array_column($productionInbox['messages'],'id')),true),'production_request_reply_due_inbox');
    $db->prepare("UPDATE dmo_commission_requests SET status='submitted' WHERE id=:id")->execute(['id'=>$requestA]);
    foreach(['needs_schedule','paid_to_start','ready_to_deliver'] as $filter){
        [$code,$work]=qa_http('/api/admin/requests.php?filter='.$filter,$tokenAdmin,'__Host-dmo_admin');
        qa_positive($code===200&&$overview['counts'][$filter]===count($work['requests']),'artist_queue_count_'.$filter);
    }
    $afterReply=qa_seed($db,"INSERT INTO dmo_messages (conversation_id,sender_id,body,is_internal) VALUES (:conv,:sender,'Synthetic artist response',0)",['conv'=>$conv,'sender'=>$admin]);
    [$code,$queue]=qa_http('/api/admin/requests.php?filter=reply_due',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&!in_array($requestA,array_map('intval',array_column($queue['requests'],'id')),true),'artist_reply_clears_queue');
    $db->prepare('DELETE FROM dmo_messages WHERE id=:id')->execute(['id'=>$afterReply]);
    $db->prepare("DELETE FROM dmo_messages WHERE id IN (:a,:b,:c)")->execute(['a'=>$messageId,'b'=>$internalId,'c'=>$replyId]);
    $db->prepare("UPDATE dmo_commission_requests SET preferred_contact='onsite' WHERE id=:id")->execute(['id'=>$requestA]);
    $oldQuote=$quoteId;
    $db->prepare('UPDATE dmo_quotes SET validity_ends_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=:id')->execute(['id'=>$oldQuote]);
    [$code,$expired]=qa_http('/api/request.php?ref='.urlencode($referenceA),$tokenA);
    qa_positive($code===200 && $expired['request']['workflow_stage']==='quote_expired' &&
        $expired['quotes'][0]['can_respond']===false,'expired_offer_not_actionable');
    [$code,$history]=qa_http('/api/requests.php',$tokenA);
    qa_positive($code===200&&!in_array($referenceA,$history['attention']['quotes'],true),'customer_attention_excludes_expired_quote');
    [$code,$follow]=qa_http('/api/admin/requests.php?filter=quote_followup',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200 && count($follow['requests'])===1 &&
        $follow['requests'][0]['workflow_stage']==='quote_expired','expired_offer_in_followup_queue');
    $withdraw=['action'=>'withdraw_quote','request_id'=>$requestA,'quote_id'=>$oldQuote,'confirm_withdrawal'=>true];
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',array_replace($withdraw,['confirm_withdrawal'=>false]),$csrfAdmin);
    qa_positive($code===422,'withdraw_requires_confirmation');
    [$code,$withdrawn]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',$withdraw,$csrfAdmin);
    qa_positive($code===200 && $withdrawn['status']==='superseded' && $withdrawn['payment_collected']===false,'artist_withdraws_expired_offer');
    [$code,$inactive]=qa_http('/api/request.php?ref='.urlencode($referenceA),$tokenA);
    qa_positive($code===200 && $inactive['request']['workflow_stage']==='quote_inactive' &&
        $inactive['quotes'][0]['can_respond']===false,'withdrawn_offer_preserved_read_only');
    [$code,$renewal]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',[
        'action'=>'create_quote_draft','request_id'=>$requestA,'currency'=>'CAD','scope'=>'Synthetic original portrait renewed',
        'revision_allowance'=>2,'lines'=>[['description'=>'Illustration','amount_minor'=>2700],['description'=>'Background','amount_minor'=>300]]
    ],$csrfAdmin);
    qa_positive($code===201 && $renewal['version']===2,'new_version_after_withdrawal');
    $quoteId=$renewal['quote_id'];
    [$code]=qa_http('/api/admin/quote-send.php',$tokenAdmin,'__Host-dmo_admin',[
        'request_id'=>$requestA,'quote_id'=>$quoteId,'validity_days'=>7
    ],$csrfAdmin);
    qa_positive($code===200,'renewed_offer_released');

    [$code]=qa_http('/api/quote-response.php',$tokenB,
        '__Host-dmo_session',[
            'ref'=>$referenceA,'quote_id'=>$quoteId,'decision'=>'accept',
            'policy_version'=>'qa-policy-v0','acknowledge_quote'=>true,
        ],$csrfB);
    qa_positive($code===404,'other_customer_cannot_accept_quote');
    [$code]=qa_http('/api/quote-response.php',$tokenA,
        '__Host-dmo_session',[
            'ref'=>$referenceA,'quote_id'=>$quoteId,'decision'=>'accept',
            'policy_version'=>'qa-policy-v0','acknowledge_quote'=>true,
            'total_minor'=>1,
        ],$csrfA);
    qa_positive($code===422,'client_price_override_rejected');

    [$code]=qa_http('/api/quote-response.php',$tokenA,'__Host-dmo_session',[
        'ref'=>$referenceA,'quote_id'=>$quoteId,'decision'=>'accept','policy_version'=>'qa-policy-v1','acknowledge_quote'=>true
    ],$csrfA);
    qa_positive($code===409,'cannot_substitute_current_policy_for_historical_agreement');
    [$code,$accept]=qa_http('/api/quote-response.php',$tokenA,
        '__Host-dmo_session',[
            'ref'=>$referenceA,'quote_id'=>$quoteId,'decision'=>'accept',
            'policy_version'=>'qa-policy-v0','acknowledge_quote'=>true,
        ],$csrfA);
    qa_positive($code===200 && ($accept['status']??'')==='accepted' &&
        ($accept['payment_collected']??true)===false,
        'customer_accepts_exact_uncharged_quote_under_historical_policy');
    [$code]=qa_http('/api/quote-response.php',$tokenA,
        '__Host-dmo_session',[
            'ref'=>$referenceA,'quote_id'=>$quoteId,'decision'=>'accept',
            'policy_version'=>'qa-policy-v0','acknowledge_quote'=>true,
        ],$csrfA);
    qa_positive($code===409,'accepted_quote_replay_rejected');

    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',array_replace($withdraw,['quote_id'=>$quoteId]),$csrfAdmin);
    qa_positive($code===409,'accepted_quote_cannot_be_withdrawn');
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

    $orderId=$order['order_id'];
    [$code,,$print]=qa_http('/commission-summary.php?ref='.$referenceA,$tokenA);
    qa_positive($code===200&&str_contains($print,'Accepted quote')&&str_contains($print,'CAD 30.00')&&str_contains($print,'Not verified')&&!str_contains($print,'A private test drawing.'),'private_print_summary_accepted_unpaid');
    [$code,,$otherPrint]=qa_http('/commission-summary.php?ref='.$referenceA,$tokenB);
    qa_positive($code===404&&!str_contains($otherPrint,$referenceA),'print_summary_cross_account_denied');
    [$code]=qa_http('/commission-summary.php?ref='.$referenceA);
    qa_positive($code===401,'print_summary_requires_customer');
    $link=['action'=>'issue_checkout_link','request_id'=>$requestA,'order_id'=>$orderId,'expected_link_id'=>null,'url'=>'https://checkout.example.invalid/invoice/ci-first','currency'=>'CAD','amount_minor'=>3000,'confirm_invoice'=>true];
    foreach(['http://checkout.example.invalid/invoice/ci-first','https://checkout.example.invalid.evil.invalid/invoice/ci-first','https://checkout.example.invalid@evil.invalid/invoice/ci-first','https://checkout.example.invalid/invoice/../other','https://checkout.example.invalid/invoice/ci-first?redirect=evil','https://checkout.example.invalid/invoice/%2fother'] as $bad){
        [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',array_replace($link,['url'=>$bad]),$csrfAdmin);
        qa_positive($code===422,'unsafe_checkout_url_rejected');
    }
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',array_replace($link,['amount_minor'=>1]),$csrfAdmin);
    qa_positive($code===409,'checkout_requires_exact_quote_total');
    [$code,$created]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',$link,$csrfAdmin);
    qa_positive($code===201&&$created['money_moved']===false,'artist_issues_invoice_without_charge');
    $linkId=$created['link_id'];
    [$code,$detail]=qa_http('/api/request.php?ref='.$referenceA,$tokenA);
    qa_positive($code===200&&$detail['checkout_links'][0]['id']===$linkId&&!str_contains(json_encode($detail,JSON_THROW_ON_ERROR),'ci-first'),'customer_gets_opaque_checkout_link_only');
    [$code]=qa_http('/api/checkout.php?id='.$linkId,$tokenB);
    qa_positive($code===404,'other_customer_checkout_denied');
    [$code]=qa_http('/api/checkout.php?id='.$linkId,$tokenA);
    qa_positive($code===303,'owner_checkout_redirect');
    [$code,,$print]=qa_http('/commission-summary.php?ref='.$referenceA,$tokenA);
    qa_positive($code===200&&!str_contains($print,'ci-first')&&!str_contains($print,'checkout.example.invalid')&&!str_contains($print,'api/checkout.php'),'print_summary_excludes_invoice_url');
    $q=$db->prepare('SELECT url_ciphertext FROM dmo_checkout_links WHERE id=:id');$q->execute(['id'=>$linkId]);
    qa_positive(!str_contains((string)$q->fetchColumn(),'ci-first'),'checkout_url_encrypted_at_rest');
    $q=$db->prepare('SELECT url_fingerprint,url_ciphertext FROM dmo_checkout_links WHERE id=:id');$q->execute(['id'=>$linkId]);$originalInvoice=$q->fetch(PDO::FETCH_ASSOC);
    $db->prepare('UPDATE dmo_checkout_links SET url_fingerprint=:hash WHERE id=:id')->execute(['hash'=>str_repeat('0',64),'id'=>$linkId]);
    [$code]=qa_http('/api/checkout.php?id='.$linkId,$tokenA);
    qa_positive($code===404,'checkout_redirect_denies_fingerprint_mismatch');
    $db->prepare('UPDATE dmo_checkout_links SET url_fingerprint=:hash,url_ciphertext=:cipher WHERE id=:id')->execute(['hash'=>$originalInvoice['url_fingerprint'],'cipher'=>$originalInvoice['url_ciphertext']."\n",'id'=>$linkId]);
    [$code]=qa_http('/api/checkout.php?id='.$linkId,$tokenA);
    qa_positive($code===404,'checkout_redirect_denies_noncanonical_ciphertext');
    $db->prepare('UPDATE dmo_checkout_links SET url_ciphertext=:cipher WHERE id=:id')->execute(['cipher'=>$originalInvoice['url_ciphertext'],'id'=>$linkId]);
    [$code]=qa_http('/api/checkout.php?id='.$linkId,$tokenA);
    qa_positive($code===303,'checkout_redirect_restored_after_fixture_repair');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',array_replace($link,['expected_link_id'=>$linkId,'url'=>'https://checkout.example.invalid/invoice/ci-next']),$csrfAdmin);
    qa_positive($code===409,'existing_invoice_must_be_closed_before_replacement');
    $revoke=['action'=>'revoke_checkout_link','request_id'=>$requestA,'order_id'=>$orderId,'expected_link_id'=>$linkId,'confirm_external_closed'=>true];
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',$revoke,$csrfAdmin);
    qa_positive($code===200,'artist_removes_closed_invoice_link');
    [$code]=qa_http('/api/checkout.php?id='.$linkId,$tokenA);
    qa_positive($code===404,'revoked_checkout_denied');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',array_replace($link,['expected_link_id'=>$linkId]),$csrfAdmin);
    qa_positive($code===409,'same_invoice_cannot_be_reused');
    [$code,$created]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',array_replace($link,['expected_link_id'=>$linkId,'url'=>'https://checkout.example.invalid/invoice/ci-next']),$csrfAdmin);
    qa_positive($code===201,'new_invoice_after_external_closure');
    $checkoutId=$created['link_id'];
    $db->prepare("UPDATE dmo_checkout_links SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE) WHERE id=:id")->execute(['id'=>$checkoutId]);
    [$code]=qa_http('/api/checkout.php?id='.$checkoutId,$tokenA);
    qa_positive($code===404,'expired_checkout_denied');
    [$code,$queue]=qa_http('/api/admin/requests.php?filter=checkout_followup',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&count($queue['requests'])===1&&$queue['requests'][0]['id']===$requestA,'expired_invoice_in_followup_queue');
    $db->prepare("UPDATE dmo_checkout_links SET expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=:id")->execute(['id'=>$checkoutId]);
    $deadline=(new DateTimeImmutable('today',new DateTimeZone('UTC')))->modify('+5 days')->format('Y-m-d');
    $schedule=['action'=>'set_order_deadline','request_id'=>$requestA,'order_id'=>$orderId,
        'expected_deadline'=>null,'agreed_deadline'=>$deadline,'confirm_agreement'=>true];
    [$code]=qa_http('/api/admin/request-actions.php',$tokenA,'__Host-dmo_admin',$schedule,$csrfA);
    qa_positive(in_array($code,[401,403],true),'customer_cannot_edit_order_deadline');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',$schedule,null);
    qa_positive($code===403,'order_deadline_requires_csrf');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',
        array_replace($schedule,['agreed_deadline'=>'2026-02-30']),$csrfAdmin);
    qa_positive($code===422,'invalid_order_deadline_rejected');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',
        array_replace($schedule,['confirm_agreement'=>false]),$csrfAdmin);
    qa_positive($code===422,'explicit_deadline_agreement_required');
    [$code,$saved]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',$schedule,$csrfAdmin);
    qa_positive($code===200 && $saved['agreed_deadline']===$deadline && $saved['payment_status']==='unverified',
        'artist_records_agreed_date_without_payment');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',$schedule,$csrfAdmin);
    qa_positive($code===409,'stale_deadline_conflict_rejected');
    [$code,$queue]=qa_http('/api/admin/requests.php?filter=due',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200 && count($queue['requests'])===1 &&
        $queue['requests'][0]['workflow_stage']==='awaiting_arrangement' &&
        $queue['requests'][0]['order_deadline']===$deadline,'due_order_queue_matches_actual_order');
    [$code,$agenda]=qa_http('/api/admin/requests.php?filter=scheduled&from_date='.$deadline.'&to_date='.$deadline,$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&count($agenda['requests'])===1&&$agenda['requests'][0]['id']===$requestA,'agreed_agenda_inclusive_dates');
    $calendarPath='/api/admin/requests.php?filter=scheduled&format=calendar&from_date='.$deadline.'&to_date='.$deadline;
    [$code,,$calendar]=qa_http($calendarPath,$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&str_contains($calendar,"BEGIN:VCALENDAR\r\n")&&str_contains($calendar,'UID:'.$referenceA.'@drawmeone.com')&&str_contains($calendar,'DTSTART;VALUE=DATE:'.str_replace('-','',$deadline))&&!str_contains($calendar,'Test A')&&!str_contains($calendar,'private test drawing'),'agenda_calendar_minimal_all_day_event');
    [$code]=qa_http($calendarPath,$tokenA);
    qa_positive(in_array($code,[401,403],true),'calendar_export_artist_only');
    [$code]=qa_http('/api/admin/requests.php?filter=scheduled&format=calendar',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===422,'calendar_requires_bounded_range');
    [$code]=qa_http('/api/admin/requests.php?filter=requested_dates&format=calendar&from_date='.$deadline.'&to_date='.$deadline,$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===422,'calendar_excludes_unagreed_dates');
    [$code,$agenda]=qa_http('/api/admin/requests.php?filter=scheduled&to_date=2000-01-01',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$agenda['requests']===[],'agreed_agenda_excludes_outside_range');
    foreach(['filter=scheduled&from_date=2026-02-30','filter=scheduled&from_date=2027-01-01&to_date=2026-01-01','filter=all&from_date=2026-01-01'] as $query){
        [$code]=qa_http('/api/admin/requests.php?'.$query,$tokenAdmin,'__Host-dmo_admin');
        qa_positive($code===422,'invalid_schedule_query_rejected');
    }
    [$code,$agenda]=qa_http('/api/admin/requests.php?filter=requested_dates',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&!in_array($requestA,array_column($agenda['requests'],'id'),true),'confirmed_order_not_in_requested_date_agenda');
    [$code,$history]=qa_http('/api/requests.php',$tokenA);
    qa_positive($code===200 && $history['requests'][0]['workflow_stage']==='awaiting_arrangement' &&
        $history['requests'][0]['order_deadline']===$deadline,'customer_history_reflects_order_not_initial_request_status');
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
        ($customerDetail['orders'][0]['total_minor']??null)===3000 &&
        $customerDetail['orders'][0]['agreed_deadline']===$deadline,
        'customer_sees_correct_unpaid_order');
    [$code,$otherDetail]=qa_http('/api/request.php?ref='.urlencode($referenceB),$tokenB);
    qa_positive($code===200 && ($otherDetail['quotes']??[])===[] &&
        ($otherDetail['orders']??[])===[],
        'other_customer_not_exposed_to_order');

    [$code,$sentMessage]=qa_http('/api/request-message.php',$tokenA,
        '__Host-dmo_session',[
            'ref'=>$referenceA,'body'=>'Please use soft shading.','operation_key'=>'10000000-0000-4000-8000-000000000001',
        ],$csrfA);
    qa_positive($code===201,'customer_can_send_private_message');
    [$code,$replay]=qa_http('/api/request-message.php',$tokenA,'__Host-dmo_session',['ref'=>$referenceA,'body'=>'Please use soft shading.','operation_key'=>'10000000-0000-4000-8000-000000000001'],$csrfA);
    qa_positive($code===200&&($replay['message_id']??null)===$sentMessage['message_id']&&($replay['already_saved']??false),'customer_message_retry_returns_original_id');
    [$code]=qa_http('/api/request-message.php',$tokenA,'__Host-dmo_session',['ref'=>$referenceA,'body'=>'Changed message.','operation_key'=>'10000000-0000-4000-8000-000000000001'],$csrfA);
    qa_positive($code===409,'customer_message_key_conflict_rejected');
    [$code]=qa_http('/api/request-message.php',$tokenB,
        '__Host-dmo_session',[
            'ref'=>$referenceA,'body'=>'Unauthorized message.',
        ],$csrfB);
    qa_positive($code===404,'other_customer_cannot_message_request');
    [$code,$privateNote]=qa_http('/api/admin/request-actions.php',
        $tokenAdmin,'__Host-dmo_admin',[
            'action'=>'add_private_note','request_id'=>$requestA,
            'body'=>'CI ARTIST-ONLY PRIVATE NOTE','operation_key'=>'10000000-0000-4000-8000-000000000002',
        ],$csrfAdmin);
    qa_positive($code===201 && ($privateNote['visibility']??'')==='internal_only',
        'artist_private_note_saved_as_internal');
    [$code,$replay]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'add_private_note','request_id'=>$requestA,'body'=>'CI ARTIST-ONLY PRIVATE NOTE','operation_key'=>'10000000-0000-4000-8000-000000000002'],$csrfAdmin);
    qa_positive($code===200&&($replay['message_id']??null)===$privateNote['message_id'],'artist_private_note_retry_returns_original_id');
    [$code,$publicReply]=qa_http('/api/admin/request-actions.php',
        $tokenAdmin,'__Host-dmo_admin',[
            'action'=>'send_customer_message','request_id'=>$requestA,
            'body'=>'Understood, soft shading it is.','operation_key'=>'10000000-0000-4000-8000-000000000003',
        ],$csrfAdmin);
    qa_positive($code===201 && ($publicReply['visibility']??'')==='customer_visible',
        'artist_public_reply_saved_separately');
    [$code,$replay]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'send_customer_message','request_id'=>$requestA,'body'=>'Understood, soft shading it is.','operation_key'=>'10000000-0000-4000-8000-000000000003'],$csrfAdmin);
    qa_positive($code===200&&($replay['message_id']??null)===$publicReply['message_id'],'artist_reply_retry_returns_original_id');
    [$code,$thread]=qa_http('/api/request.php?ref='.urlencode($referenceA),$tokenA);
    qa_positive($code===200 && count($thread['messages']??[])===2 &&
        !str_contains(json_encode($thread,JSON_THROW_ON_ERROR),
            'CI ARTIST-ONLY PRIVATE NOTE'),
        'internal_note_excluded_from_real_http_response');

    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',[
        'action'=>'set_review_status','request_id'=>$requestA,'expected_status'=>'submitted','status'=>'rejected'
    ],$csrfAdmin);
    qa_positive($code===409,'accepted_order_cannot_be_declined_as_new_request');
    $conversation=$db->prepare('SELECT id FROM dmo_conversations WHERE request_id=:id');
    $conversation->execute(['id'=>$requestA]);$cid=$conversation->fetchColumn();
    $seed=$db->prepare('INSERT INTO dmo_messages (conversation_id,sender_id,body,is_internal) VALUES (:c,:u,:body,:internal)');
    for($i=0;$i<110;$i++)$seed->execute(['c'=>$cid,'u'=>$i%2?$admin:$a,'body'=>'CI long thread '.$i,'internal'=>$i%2]);
    [$code,$recent]=qa_http('/api/request.php?ref='.urlencode($referenceA),$tokenA);
    qa_positive($code===200 && count($recent['messages'])===40 && $recent['messages_have_older']===true,'customer_recent_thread_bounded');
    [$code,$full]=qa_http('/api/request.php?ref='.urlencode($referenceA).'&thread=full',$tokenA);
    qa_positive($code===200 && count($full['messages'])===57 && $full['messages_have_older']===false &&
        !str_contains(json_encode($full,JSON_THROW_ON_ERROR),'CI ARTIST-ONLY PRIVATE NOTE'),'customer_full_thread_excludes_internal');
    [$code,$recent]=qa_http('/api/admin/request.php?id='.$requestA,$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200 && count($recent['detail']['messages'])===100 && $recent['detail']['messages_have_older']===true,'artist_recent_thread_bounded');
    [$code,$full]=qa_http('/api/admin/request.php?id='.$requestA.'&thread=full',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200 && count($full['detail']['messages'])===113,'artist_full_thread_includes_internal');
    qa_positive($full['detail']['conversation_message_count']===113&&$full['detail']['conversation_message_limit']===500,'artist_conversation_capacity_metadata');
    for($i=0;$i<387;$i++)$seed->execute(['c'=>$cid,'u'=>$admin,'body'=>'CI capacity fixture '.$i,'internal'=>1]);
    try {
        [$code,$capacity]=qa_http('/api/request.php?ref='.urlencode($referenceA).'&thread=full',$tokenA);
        qa_positive($code===200&&$capacity['conversation_full']===true&&count($capacity['messages'])===57&&!isset($capacity['conversation_message_count']),'customer_capacity_without_private_count');
        [$code,$capacity]=qa_http('/api/admin/request.php?id='.$requestA.'&thread=full',$tokenAdmin,'__Host-dmo_admin');
        qa_positive($code===200&&count($capacity['detail']['messages'])===500&&$capacity['detail']['conversation_message_count']===500,'artist_full_conversation_capacity');
        [$code,$blocked]=qa_http('/api/request-message.php',$tokenA,'__Host-dmo_session',['ref'=>$referenceA,'body'=>'New message at capacity.','operation_key'=>'10000000-0000-4000-8000-000000000011'],$csrfA);
        qa_positive($code===409&&$blocked['error']==='conversation_limit','customer_new_message_blocked_at_capacity');
        [$code,$replay]=qa_http('/api/request-message.php',$tokenA,'__Host-dmo_session',['ref'=>$referenceA,'body'=>'Please use soft shading.','operation_key'=>'10000000-0000-4000-8000-000000000001'],$csrfA);
        qa_positive($code===200&&$replay['message_id']===$sentMessage['message_id'],'customer_original_retry_at_capacity');
        [$code,$blocked]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'add_private_note','request_id'=>$requestA,'body'=>'New note at capacity','operation_key'=>'10000000-0000-4000-8000-000000000012'],$csrfAdmin);
        qa_positive($code===409&&$blocked['error']==='conversation_limit','artist_new_note_blocked_at_capacity');
        [$code,$replay]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'add_private_note','request_id'=>$requestA,'body'=>'CI ARTIST-ONLY PRIVATE NOTE','operation_key'=>'10000000-0000-4000-8000-000000000002'],$csrfAdmin);
        qa_positive($code===200&&$replay['message_id']===$privateNote['message_id'],'artist_original_retry_at_capacity');
    } finally {
        $db->prepare("DELETE FROM dmo_messages WHERE conversation_id=:id AND body LIKE 'CI capacity fixture %'")->execute(['id'=>$cid]);
    }
    $activity=$full['detail']['activity'];
    qa_positive(count($activity['events'])<=50&&in_array('Quote released to customer',array_column($activity['events'],'label'),true),'artist_activity_includes_quote_release');
    foreach($activity['events'] as $event)qa_positive(array_keys($event)===['label','created_at'],'activity_event_minimal_fields');
    qa_positive(!isset($customerDetail['activity']),'customer_detail_excludes_artist_activity');
    $activityInsert=$db->prepare("INSERT INTO dmo_audit_log (actor_id,action,entity_type,entity_id,details_json) VALUES (:actor,'request.private_note_created','commission_request',:id,'{}')");
    for($i=0;$i<52;$i++)$activityInsert->execute(['actor'=>$admin,'id'=>$requestA]);
    [$code,$recentActivity]=qa_http('/api/admin/request.php?id='.$requestA,$tokenAdmin,'__Host-dmo_admin');
    $activity=$recentActivity['detail']['activity'];
    qa_positive($code===200&&count($activity['events'])===50&&$activity['has_older']===true&&is_string($activity['next_before']),'activity_history_bounded_cursor');
    $path='/api/admin/request.php?id='.$requestA.'&activity_before='.$activity['next_before'];
    [$code,$olderActivity]=qa_http($path,$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&count($olderActivity['activity']['events'])>=2&&in_array('Quote released to customer',array_column($olderActivity['activity']['events'],'label'),true)&&!isset($olderActivity['detail']),'older_activity_only_projection');
    [$code]=qa_http($path,$tokenA);qa_positive(in_array($code,[401,403],true),'older_activity_admin_only');
    [$code]=qa_http('/api/admin/request.php?id='.$requestA.'&activity_before=0',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===422,'invalid_activity_cursor');
    [$code,$messages]=qa_http('/api/admin/messages.php?filter=internal&page=1',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200 && count($messages['messages'])===50 && $messages['has_more']===true &&
        count(array_filter($messages['messages'],fn($m)=>(int)$m['is_internal']!==1))===0,'artist_message_filter_and_page');
    [$code,$messages]=qa_http('/api/admin/messages.php?filter=customer&page=2',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200 && count($messages['messages'])===6 && $messages['has_more']===false &&
        count(array_filter($messages['messages'],fn($m)=>$m['sender_role']!=='customer'))===0,'artist_customer_messages_second_page');
    [$code]=qa_http('/api/admin/messages.php?filter=internal',$tokenA,'__Host-dmo_admin');
    qa_positive(in_array($code,[401,403],true),'customer_cannot_read_artist_inbox');

    qa_positive((int)$db->query('SELECT COUNT(*) FROM dmo_payments')->fetchColumn()===0,
        'full_positive_lifecycle_never_creates_payment');
    $evidence=['action'=>'record_payment_evidence','request_id'=>$requestA,'order_id'=>$orderId,'method'=>'bank_transfer','reference'=>'CI-ONLY-TRANSACTION-123','currency'=>'CAD','amount_minor'=>3000];
    [$code]=qa_http('/api/admin/request-actions.php',$tokenA,'__Host-dmo_admin',$evidence,$csrfA);
    qa_positive(in_array($code,[401,403],true),'customer_cannot_record_payment');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',array_replace($evidence,['amount_minor'=>2999]),$csrfAdmin);
    qa_positive($code===409,'partial_payment_not_accepted');
    [$code,$pending]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',$evidence,$csrfAdmin);
    qa_positive($code===201 && $pending['status']==='pending_verification' && $pending['payment_status']==='unverified','payment_evidence_not_payment_confirmation');
    [$code]=qa_http('/api/admin/finance.php',$tokenA,'__Host-dmo_admin');
    qa_positive(in_array($code,[401,403],true),'finance_admin_only');
    [$code,$finance]=qa_http('/api/admin/finance.php?state=pending&currency=CAD',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&count($finance['records'])===1&&$finance['records'][0]['record_state']==='pending'&&$finance['summary'][0]['amount_minor']===3000,'finance_pending_not_reconciled');
    qa_positive(!str_contains(json_encode($finance,JSON_THROW_ON_ERROR),'manual-hmac-v1')&&!str_contains(json_encode($finance,JSON_THROW_ON_ERROR),'CI-ONLY-TRANSACTION'),'finance_excludes_provider_reference');
    [$code]=qa_http('/api/checkout.php?id='.$checkoutId,$tokenA);
    qa_positive($code===404,'pending_evidence_suspends_invoice');
    $q=$db->prepare('SELECT status,url_ciphertext FROM dmo_checkout_links WHERE id=:id');$q->execute(['id'=>$checkoutId]);$suspended=$q->fetch(PDO::FETCH_ASSOC);
    qa_positive($suspended['status']==='suspended'&&$suspended['url_ciphertext']==='','suspended_invoice_cannot_resurface');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',$evidence,$csrfAdmin);
    qa_positive($code===409,'duplicate_evidence_denied');
    [$code,$queue]=qa_http('/api/admin/requests.php?filter=payment_review',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200 && count($queue['requests'])===1,'payment_review_queue');
    $confirm=array_replace($evidence,['action'=>'verify_payment_evidence','payment_id'=>$pending['payment_id'],'confirm_received'=>true]);
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',array_replace($confirm,['reference'=>'CI-WRONG-REFERENCE']),$csrfAdmin);
    qa_positive($code===409,'mismatched_reference_denied');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',array_replace($confirm,['confirm_received'=>false]),$csrfAdmin);
    qa_positive($code===422,'receiving_account_confirmation_required');
    [$code,$paid]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',$confirm,$csrfAdmin);
    qa_positive($code===200 && $paid['payment_status']==='paid' && $paid['provider_verified']===false && $paid['money_moved']===false,'manual_full_payment_reconciled_without_charge');
    [$code]=qa_http('/api/checkout.php?id='.$checkoutId,$tokenA);
    qa_positive($code===404,'paid_order_checkout_denied');
    [$code,$queue]=qa_http('/api/admin/requests.php?filter=checkout_followup',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&count($queue['requests'])===1,'suspended_invoice_needs_external_closure_review');
    [$code,$closed]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',array_replace($revoke,['expected_link_id'=>$checkoutId]),$csrfAdmin);
    qa_positive($code===200&&$closed['status']==='revoked','settled_external_invoice_closed_locally');
    [$code,$queue]=qa_http('/api/admin/requests.php?filter=checkout_followup',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$queue['requests']===[],'closed_invoice_leaves_followup_queue');
    [$code,$customerPaid]=qa_http('/api/request.php?ref='.urlencode($referenceA),$tokenA);
    qa_positive($code===200 && $customerPaid['orders'][0]['payment_status']==='paid' &&
        !str_contains(json_encode($customerPaid,JSON_THROW_ON_ERROR),'CI-ONLY-TRANSACTION') &&
        !str_contains(json_encode($customerPaid,JSON_THROW_ON_ERROR),'manual-hmac-v1'),'customer_sees_paid_without_private_evidence');
    [$code,$finance]=qa_http('/api/admin/finance.php?state=reconciled&currency=CAD',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&count($finance['records'])===1&&$finance['records'][0]['record_state']==='reconciled','finance_reconciled_record');
    [$code,,$print]=qa_http('/commission-summary.php?ref='.$referenceA,$tokenA);
    qa_positive($code===200&&str_contains($print,'Recorded as reconciled')&&!str_contains($print,'CI-ONLY-TRANSACTION'),'print_summary_local_reconciled_state_no_reference');
    [$code,$none]=qa_http('/api/admin/finance.php?state=reconciled&currency=USD',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$none['records']===[]&&$none['matching_count']===0,'finance_currency_filter');
    $recordDate=substr($finance['records'][0]['created_at'],0,10);
    [$code,$dated]=qa_http('/api/admin/finance.php?from='.$recordDate.'&to='.$recordDate,$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&count($dated['records'])===1&&$dated['date_basis']==='record_created_utc'&&$dated['date_from']===$recordDate,'finance_inclusive_utc_date_range');
    [$code,$dated]=qa_http('/api/admin/finance.php?from=2000-01-01&to=2000-01-02',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$dated['records']===[]&&$dated['summary']===[],'finance_date_range_applies_to_summary');
    [$code]=qa_http('/api/admin/finance.php?from=2026-02-30',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===422,'finance_invalid_date_denied');
    [$code]=qa_http('/api/admin/finance.php?from=2026-11-25&to=2026-11-24',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===422,'finance_reversed_range_denied');
    [$code,$none]=qa_http('/api/admin/finance.php?before='.$finance['records'][0]['id'],$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$none['records']===[]&&$none['matching_count']>=1,'finance_cursor');
    [$code]=qa_http('/api/admin/finance.php?state=invalid',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===422,'finance_invalid_filter_denied');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',$confirm,$csrfAdmin);
    qa_positive($code===409,'paid_reconciliation_replay_denied');
    $db->prepare("UPDATE dmo_commission_requests SET status='completed' WHERE id=:id")
       ->execute(['id'=>$requestA]);
    [$code]=qa_http('/api/request-message.php',$tokenA,
        '__Host-dmo_session',[
            'ref'=>$referenceA,'body'=>'Should be blocked after completion.',
        ],$csrfA);
    qa_positive($code===409,'closed_request_rejects_new_messages');

    $db->prepare("UPDATE dmo_commission_requests SET status='submitted' WHERE id=:id")->execute(['id'=>$requestB]);
    [$code,$intake]=qa_http('/api/admin/intake.php',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$intake['intake']['paused']===true&&$intake['intake']['version']===0,'intake_control_starts_paused');
    $settings=['expected_version'=>0,'paused'=>false,'capacity'=>1,'confirm_change'=>true];
    [$code]=qa_http('/api/admin/intake.php',$tokenA,'__Host-dmo_admin',$settings,$csrfA);
    qa_positive(in_array($code,[401,403],true),'customer_cannot_change_intake');
    [$code]=qa_http('/api/admin/intake.php',$tokenAdmin,'__Host-dmo_admin',$settings);
    qa_positive($code===403,'intake_control_requires_csrf');
    [$code,$saved]=qa_http('/api/admin/intake.php',$tokenAdmin,'__Host-dmo_admin',$settings,$csrfAdmin);
    qa_positive($code===200&&$saved['intake']['reason']==='at_capacity','existing_active_request_consumes_capacity');
    $version=$saved['intake']['version'];
    [$code]=qa_http('/api/admin/intake.php',$tokenAdmin,'__Host-dmo_admin',$settings,$csrfAdmin);
    qa_positive($code===409,'stale_intake_settings_rejected');
    [$code,$public]=qa_http('/api/request-status.php');
    qa_positive($code===200&&$public['requests_open']===false&&!isset($public['capacity'])&&!isset($public['active_requests']),'public_intake_has_no_queue_counts');
    $payload=['idempotency_key'=>'b1899b00-7b76-4d9c-b5a0-aef173b5e0d1','tier_id'=>null,'addon_ids'=>[],'subject_type'=>'portrait','brief'=>'Synthetic new commission for capacity testing.','preferred_contact'=>'onsite','preferred_deadline'=>null,'policy_version'=>'qa-policy-v1','policy_sha256'=>dmo_config('DMO_REQUEST_POLICY_SHA256'),'acknowledge_review'=>true];
    [$code,$blocked]=qa_http('/api/requests.php',$tokenA,'__Host-dmo_session',$payload,$csrfA);
    qa_positive($code===503&&$blocked['error']==='commissions_at_capacity','capacity_blocks_new_request');
    [$code,$saved]=qa_http('/api/admin/intake.php',$tokenAdmin,'__Host-dmo_admin',array_replace($settings,['expected_version'=>$version,'capacity'=>2]),$csrfAdmin);
    qa_positive($code===200&&$saved['intake']['requests_open']===true,'capacity_increase_reopens_intake_under_existing_gates');
    [$code,$policy]=qa_http('/api/policy.php');
    qa_positive($code===200&&$policy['policy']['sha256']===$payload['policy_sha256'],'published_policy_matches_pin');
    [$code]=qa_http('/api/policy.php?version=qa-policy-v1&sha256='.str_repeat('0',64));
    qa_positive($code===404,'policy_digest_mismatch_rejected');
    [$code]=qa_http('/api/requests.php',$tokenA,'__Host-dmo_session',array_replace($payload,['policy_sha256'=>str_repeat('0',64)]),$csrfA);
    qa_positive($code===422,'stale_policy_digest_rejected');
    [$code,$submitted]=qa_http('/api/requests.php',$tokenA,'__Host-dmo_session',$payload,$csrfA);
    qa_positive($code===201,'new_request_consumes_remaining_capacity');
    [$code,$receipt]=qa_http('/api/request.php?ref='.$submitted['public_ref'],$tokenA);
    qa_positive($code===200&&$receipt['request']['policy_sha256']===$payload['policy_sha256'],'request_receipt_preserves_policy_digest');
    [$code,$public]=qa_http('/api/request-status.php');
    qa_positive($public['intake_reason']==='at_capacity','intake_capacity_updates_after_submission');
    [$code,$saved]=qa_http('/api/admin/intake.php',$tokenAdmin,'__Host-dmo_admin',array_replace($settings,['expected_version'=>$saved['intake']['version'],'paused'=>true,'capacity'=>2]),$csrfAdmin);
    qa_positive($code===200&&$saved['intake']['reason']==='paused','artist_can_pause_intake');
    [$code,$retry]=qa_http('/api/requests.php',$tokenA,'__Host-dmo_session',$payload,$csrfA);
    qa_positive($code===200&&$retry['already_submitted']===true&&$retry['public_ref']===$submitted['public_ref'],'idempotent_retry_survives_intake_pause');
    $changed=$payload;$changed['brief']='Different synthetic brief for a separate artwork request.';
    [$code,$conflict]=qa_http('/api/requests.php',$tokenA,'__Host-dmo_session',$changed,$csrfA);
    qa_positive($code===409&&($conflict['error']??null)==='request_key_conflict'&&($conflict['public_ref']??null)===$submitted['public_ref'],'request_key_conflict_prevents_silent_loss_of_changed_brief');
    $lookup=['action'=>'lookup_submission','idempotency_key'=>$payload['idempotency_key']];
    [$code,$found]=qa_http('/api/requests.php',$tokenA,'__Host-dmo_session',$lookup,$csrfA);
    qa_positive($code===200&&($found['found']??false)&&($found['receipt']['public_ref']??null)===$submitted['public_ref'],'submission_receipt_lookup_while_intake_paused');
    [$code,$other]=qa_http('/api/requests.php',$tokenB,'__Host-dmo_session',$lookup,$csrfB);
    qa_positive($code===200&&($other['found']??null)===false&&($other['receipt']??null)===null,'submission_lookup_isolated_by_customer');
    [$code]=qa_http('/api/requests.php',$tokenA,'__Host-dmo_session',$lookup,null);
    qa_positive($code===403,'submission_lookup_requires_csrf');
    [$code,$history]=qa_http('/api/requests.php',$tokenA);
    qa_positive($code===200&&$history['new_requests_open']===false,'history_available_during_pause');
    $password='CI-positive-disposable-password!';
    $export=['action'=>'export','current_password'=>$password];
    [$code]=qa_http('/api/auth/privacy.php',null,'__Host-dmo_session',$export);
    qa_positive($code===401,'privacy_export_requires_account');
    [$code]=qa_http('/api/auth/privacy.php',$tokenA,'__Host-dmo_session',$export);
    qa_positive($code===403,'privacy_export_requires_csrf');
    [$code]=qa_http('/api/auth/privacy.php',$tokenA,'__Host-dmo_session',array_replace($export,['current_password'=>'wrong']),$csrfA);
    qa_positive($code===403,'privacy_export_requires_password');
    [$code,$result]=qa_http('/api/auth/privacy.php',$tokenA,'__Host-dmo_session',$export,$csrfA);
    qa_positive($code===200&&$result['export']['profile']['email']==='positive-a@example.invalid','owner_exports_own_records');
    $serialized=json_encode($result,JSON_THROW_ON_ERROR);
    qa_positive(!str_contains($serialized,$referenceB)&&!str_contains($serialized,'positive-b@example.invalid')&&!str_contains($serialized,'password_hash')&&!str_contains($serialized,'manual-hmac-v1')&&!str_contains($serialized,'storage_key'),'export_excludes_unrelated_records_and_secrets');
    qa_positive(count(array_filter($result['export']['requests'],fn($r)=>$r['public_ref']===$referenceA))===1,'export_includes_historical_request');
    $privacy=['action'=>'submit','current_password'=>$password,'kind'=>'deletion','note'=>'Synthetic deletion request for review.'];
    [$code,$opened]=qa_http('/api/auth/privacy.php',$tokenA,'__Host-dmo_session',$privacy,$csrfA);
    qa_positive($code===200&&$opened['case']['status']==='submitted'&&$opened['data_deleted']===false,'privacy_request_does_not_delete');
    [$code]=qa_http('/api/auth/privacy.php',$tokenA,'__Host-dmo_session',$privacy,$csrfA);
    qa_positive($code===409,'one_active_privacy_case');
    [$code,$other]=qa_http('/api/auth/privacy.php',$tokenB);
    qa_positive($code===200&&$other['case']===null,'other_customer_cannot_view_case');
    [$code]=qa_http('/api/admin/privacy.php',$tokenA,'__Host-dmo_admin');
    qa_positive(in_array($code,[401,403],true),'customer_cannot_read_privacy_queue');
    [$code,$queue]=qa_http('/api/admin/privacy.php',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&count($queue['cases'])===1&&$queue['cases'][0]['customer_id']===$a,'admin_privacy_queue');
    $decision=['customer_id'=>$a,'expected_version'=>$opened['case']['version'],'status'=>'in_review','reply'=>'Reviewing retention requirements.','confirm_record'=>true];
    [$code,$progress]=qa_http('/api/admin/privacy.php',$tokenAdmin,'__Host-dmo_admin',$decision,$csrfAdmin);
    qa_positive($code===200&&$progress['case']['status']==='in_review','admin_records_review');
    [$code]=qa_http('/api/admin/privacy.php',$tokenAdmin,'__Host-dmo_admin',$decision,$csrfAdmin);
    qa_positive($code===409,'privacy_stale_decision_blocked');
    [$code,$done]=qa_http('/api/admin/privacy.php',$tokenAdmin,'__Host-dmo_admin',array_replace($decision,['expected_version'=>$progress['case']['version'],'status'=>'declined','reply'=>'Synthetic retained records decision.']),$csrfAdmin);
    qa_positive($code===200&&$done['data_deleted']===false,'manual_outcome_only');
    [$code,$own]=qa_http('/api/auth/privacy.php',$tokenA);
    qa_positive($code===200&&$own['case']['status']==='declined'&&$own['case']['reply']==='Synthetic retained records decision.','customer_sees_outcome');
    [$code,$opened]=qa_http('/api/auth/privacy.php',$tokenA,'__Host-dmo_session',array_replace($privacy,['kind'=>'access']),$csrfA);
    [$code,$withdrawn]=qa_http('/api/auth/privacy.php',$tokenA,'__Host-dmo_session',['action'=>'withdraw','current_password'=>$password,'expected_version'=>$opened['case']['version']],$csrfA);
    qa_positive($code===200&&$withdrawn['case']['status']==='withdrawn','customer_withdraws_open_case');
    [$code,$queue]=qa_http('/api/admin/privacy.php',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$queue['cases']===[],'completed_or_withdrawn_cases_leave_open_queue');
    [$code,$history]=qa_http('/api/auth/privacy.php',$tokenA);
    qa_positive($code===200&&array_column($history['history']['cases'],'status')===['withdrawn','declined'],'customer_privacy_history_preserves_earlier_case');
    [$code,$older]=qa_http('/api/auth/privacy.php?before='.$history['history']['cases'][0]['version'],$tokenA);
    qa_positive($code===200&&array_column($older['history']['cases'],'status')===['declined'],'privacy_history_cursor_does_not_resurrect_old_case_events');
    [$code,$other]=qa_http('/api/auth/privacy.php',$tokenB);
    qa_positive($code===200&&$other['history']['cases']===[],'privacy_history_cross_account_denied');
    [$code,$closed]=qa_http('/api/admin/privacy.php?view=closed',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&array_column($closed['cases'],'status')===['withdrawn','declined'],'artist_closed_history_preserves_both_cases');
    [$code]=qa_http('/api/admin/privacy.php?view=closed',$tokenA,'__Host-dmo_admin');
    qa_positive(in_array($code,[401,403],true),'privacy_closed_history_admin_only');
    // A new review update must not reset the age of an old privacy request.
    $db->prepare("UPDATE dmo_commission_requests SET status='closed',updated_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 366 DAY) WHERE id=:id")->execute(['id'=>$requestB]);
    [$code,$opened]=qa_http('/api/auth/privacy.php',$tokenB,'__Host-dmo_session',$privacy,$csrfB);
    qa_positive($code===200,'retention_case_fixture');
    $db->prepare("UPDATE dmo_audit_log SET created_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 31 DAY) WHERE id=:id")->execute(['id'=>$opened['case']['version']]);
    [$code,$progress]=qa_http('/api/admin/privacy.php',$tokenAdmin,'__Host-dmo_admin',array_replace($decision,['customer_id'=>$b,'expected_version'=>$opened['case']['version']]),$csrfAdmin);
    qa_positive($code===200&&$progress['case']['status']==='in_review','retention_recent_review_update');
    [$code,$dashboard]=qa_http('/api/admin/overview.php',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$dashboard['retention']['mode']==='review_only'&&$dashboard['retention']['counts']['privacy_open_7d']===1&&$dashboard['retention']['counts']['privacy_open_30d']===1,'privacy_age_uses_original_submission');
    qa_positive($dashboard['retention']['counts']['closed_no_order_365d']===1,'retention_identifies_old_closed_request_without_order');
    qa_positive(!str_contains(json_encode($dashboard['retention'],JSON_THROW_ON_ERROR),'positive-b@example.invalid'),'retention_report_contains_no_customer_identity');
    // Newly submitted request from the intake test: confirm an unpaid order.
    $newRef=$submitted['public_ref'];
    $q=$db->prepare('SELECT id FROM dmo_commission_requests WHERE public_ref=:ref');$q->execute(['ref'=>$newRef]);$newId=(int)$q->fetchColumn();
    [$code,$detail]=qa_http('/api/request.php?ref='.$newRef,$tokenA);
    qa_positive($code===200&&$detail['can_withdraw']===true,'new_request_can_be_withdrawn');
    $withdrawal=['ref'=>$newRef,'expected_status'=>'submitted','confirm_withdrawal'=>true];
    [$code]=qa_http('/api/request-withdraw.php',$tokenB,'__Host-dmo_session',$withdrawal,$csrfB);
    qa_positive($code===404,'cross_account_withdrawal_denied');
    [$code]=qa_http('/api/request-withdraw.php',$tokenA,'__Host-dmo_session',$withdrawal);
    qa_positive($code===403,'withdrawal_requires_csrf');
    [$code,$newQuote]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'create_quote_draft','request_id'=>$newId,'currency'=>'CAD','scope'=>'Cancellation fixture','revision_allowance'=>0,'lines'=>[['description'=>'Artwork','amount_minor'=>2000]]],$csrfAdmin);
    qa_positive($code===201,'cancellation_quote_created');
    [$code]=qa_http('/api/admin/quote-send.php',$tokenAdmin,'__Host-dmo_admin',['request_id'=>$newId,'quote_id'=>$newQuote['quote_id'],'validity_days'=>7],$csrfAdmin);
    qa_positive($code===200,'cancellation_quote_sent');
    [$code]=qa_http('/api/quote-response.php',$tokenA,'__Host-dmo_session',['ref'=>$newRef,'quote_id'=>$newQuote['quote_id'],'decision'=>'accept','policy_version'=>'qa-policy-v1','acknowledge_quote'=>true],$csrfA);
    qa_positive($code===200,'cancellation_quote_accepted');
    [$code]=qa_http('/api/request-withdraw.php',$tokenA,'__Host-dmo_session',$withdrawal,$csrfA);
    qa_positive($code===409,'accepted_quote_cannot_be_withdrawn');
    [$code,$newOrder]=qa_http('/api/admin/order-create.php',$tokenAdmin,'__Host-dmo_admin',['request_id'=>$newId,'accepted_quote_id'=>$newQuote['quote_id'],'confirm_unpaid'=>true],$csrfAdmin);
    qa_positive($code===201,'cancellation_unpaid_order_confirmed');
    $cancel=['action'=>'cancel_unpaid_order','request_id'=>$newId,'order_id'=>$newOrder['order_id'],'reason'=>'mutual_agreement','confirm_no_funds'=>true,'confirm_agreement'=>true];
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',array_replace($cancel,['confirm_no_funds'=>false]),$csrfAdmin);
    qa_positive($code===422,'cancellation_requires_external_confirmation');
    [$code,$link]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'issue_checkout_link','request_id'=>$newId,'order_id'=>$newOrder['order_id'],'expected_link_id'=>null,'url'=>'https://checkout.example.invalid/invoice/cancel-fixture','currency'=>'CAD','amount_minor'=>2000,'confirm_invoice'=>true],$csrfAdmin);
    qa_positive($code===201,'cancellation_invoice_created');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',$cancel,$csrfAdmin);
    qa_positive($code===409,'open_invoice_blocks_cancellation');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'revoke_checkout_link','request_id'=>$newId,'order_id'=>$newOrder['order_id'],'expected_link_id'=>$link['link_id'],'confirm_external_closed'=>true],$csrfAdmin);
    qa_positive($code===200,'cancellation_invoice_closed');
    [$code,$done]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',$cancel,$csrfAdmin);
    qa_positive($code===200&&$done['status']==='cancelled'&&$done['money_moved']===false,'unpaid_order_cancelled_without_money_movement');
    [$code,$detail]=qa_http('/api/request.php?ref='.$newRef,$tokenA);
    qa_positive($code===200&&$detail['request']['status']==='cancelled'&&$detail['orders'][0]['status']==='cancelled'&&$detail['quotes'][0]['status']==='accepted'&&$detail['can_withdraw']===false&&$detail['checkout_links']===[],'cancelled_order_preserves_original_agreement');
    [$code]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',$cancel,$csrfAdmin);
    qa_positive($code===409,'duplicate_cancellation_rejected');
    // Reopen a synthetic no-order fixture only, then verify withdrawal of a sent offer.
    $db->prepare("UPDATE dmo_commission_requests SET status='submitted' WHERE id=:id")->execute(['id'=>$requestB]);
    [$code,$draftB]=qa_http('/api/admin/request-actions.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'create_quote_draft','request_id'=>$requestB,'currency'=>'CAD','scope'=>'Withdrawal fixture','revision_allowance'=>0,'lines'=>[['description'=>'Artwork','amount_minor'=>1000]]],$csrfAdmin);
    qa_positive($code===201,'withdrawal_fixture_quote_created');
    [$code]=qa_http('/api/admin/quote-send.php',$tokenAdmin,'__Host-dmo_admin',['request_id'=>$requestB,'quote_id'=>$draftB['quote_id'],'validity_days'=>7],$csrfAdmin);
    qa_positive($code===200,'withdrawal_fixture_quote_sent');
    [$code,$closed]=qa_http('/api/request-withdraw.php',$tokenB,'__Host-dmo_session',['ref'=>$referenceB,'expected_status'=>'submitted','confirm_withdrawal'=>true],$csrfB);
    qa_positive($code===200&&$closed['status']==='cancelled','customer_withdraws_unaccepted_request');
    [$code,$detail]=qa_http('/api/request.php?ref='.$referenceB,$tokenB);
    qa_positive($code===200&&$detail['quotes'][0]['status']==='superseded'&&$detail['can_withdraw']===false&&$detail['can_reply']===false,'withdrawal_invalidates_offer_but_preserves_history');
    // Read-only monitoring must detect corrupted states without repairing them.
    $baseline=dmo_integrity_records($db);
    $newChecks=['schema_reference_missing','schema_delivery_missing','schema_review_missing','schema_checkout_missing',
        'cancelled_request_live_quote','cancelled_request_live_order','cancelled_order_unclosed_invoice',
        'cancelled_order_staged_artwork','cancelled_order_proof'];
    foreach($newChecks as $check)qa_positive(isset($baseline[$check])&&$baseline[$check]===0,'integrity_clean_'.$check);
    $db->beginTransaction();
    try{
        $db->exec("DELETE FROM dmo_schema_migrations WHERE version='006_checkout_links'");
        qa_positive(dmo_integrity_records($db)['schema_checkout_missing']===1,'integrity_missing_checkout_migration_detected');
        qa_positive(dmo_integrity_checkout_crypto($db)['complete']===false,'missing_checkout_schema_not_clean');
    }finally{$db->rollBack();}
    $db->beginTransaction();
    try{
        $db->prepare("UPDATE dmo_quotes SET status='sent' WHERE id=:id")->execute(['id'=>$draftB['quote_id']]);
        $db->prepare("UPDATE dmo_orders SET status='awaiting_arrangement' WHERE id=:id")->execute(['id'=>$newOrder['order_id']]);
        $db->prepare("UPDATE dmo_checkout_links SET status='suspended',revoked_at=NULL WHERE id=:id")->execute(['id'=>$link['link_id']]);
        $counts=dmo_integrity_records($db);
        qa_positive($counts['cancelled_request_live_quote']===1&&$counts['cancelled_request_live_order']===1,'integrity_cancelled_request_conflicts_detected');
        $db->prepare("UPDATE dmo_orders SET status='cancelled' WHERE id=:id")->execute(['id'=>$newOrder['order_id']]);
        qa_positive(dmo_integrity_records($db)['cancelled_order_unclosed_invoice']===1,'integrity_cancelled_order_invoice_detected');
    }finally{$db->rollBack();}
    $db->beginTransaction();
    try{
        $url='https://checkout.example.invalid/invoice/crypto-fixture';
        $order=(int)$newOrder['order_id'];
        $cipher=dmo_checkout_seal($url,$order);
        $db->prepare("UPDATE dmo_checkout_links SET status='active',revoked_at=NULL,url_ciphertext=:cipher,url_fingerprint=:fingerprint WHERE id=:id")
            ->execute(['cipher'=>$cipher,'fingerprint'=>hash_hmac('sha256',$url,dmo_checkout_key()),'id'=>$link['link_id']]);
        $scan=dmo_integrity_checkout_crypto($db);
        qa_positive($scan['checked']===1&&$scan['invalid']===0&&$scan['complete'],'integrity_authenticates_valid_invoice');
        $db->prepare("UPDATE dmo_checkout_links SET url_ciphertext=:cipher WHERE id=:id")->execute(['cipher'=>dmo_checkout_seal($url,$order+1),'id'=>$link['link_id']]);
        qa_positive(dmo_integrity_checkout_crypto($db)['invalid']===1,'integrity_detects_wrong_order_binding');
        $db->prepare("UPDATE dmo_checkout_links SET url_ciphertext=:cipher,url_fingerprint=:fingerprint WHERE id=:id")
            ->execute(['cipher'=>$cipher,'fingerprint'=>str_repeat('0',64),'id'=>$link['link_id']]);
        qa_positive(dmo_integrity_checkout_crypto($db)['invalid']===1,'integrity_detects_fingerprint_mismatch');
        $db->prepare("UPDATE dmo_checkout_links SET url_ciphertext='invalid' WHERE id=:id")->execute(['id'=>$link['link_id']]);
        qa_positive(dmo_integrity_checkout_crypto($db)['invalid']===1,'integrity_detects_invalid_ciphertext');
    }finally{$db->rollBack();}
    qa_positive(dmo_integrity_checkout_crypto($db)['invalid']===0,'integrity_test_corruption_rolled_back');
    // Actual HTTP: private design drafts must not affect the published site.
    [$code,$public]=qa_http('/api/site-settings.php');
    qa_positive($code===200&&$public['presentation']==='published'&&$public['published']===false&&$public['version']===0,'design_public_safe_defaults');
    $settings=['site_style'=>'studio','color_theme'=>'ember','gallery_layout'=>'rows','default_mode'=>'light','logo'=>'01-minimal-pencil'];
    $save=['action'=>'save_draft','expected_version'=>0,'settings'=>$settings];
    [$code]=qa_http('/api/admin/design.php',$tokenAdmin,'__Host-dmo_admin',$save);
    qa_positive($code===403,'design_requires_csrf');
    [$code]=qa_http('/api/admin/design.php',$tokenAdmin,'__Host-dmo_admin',array_replace($save,['settings'=>array_replace($settings,['site_style'=>'cinema'])]),$csrfAdmin);
    qa_positive($code===422,'design_unapproved_site_style_rejected');
    [$code,$saved]=qa_http('/api/admin/design.php',$tokenAdmin,'__Host-dmo_admin',$save,$csrfAdmin);
    qa_positive($code===200&&$saved['draft_version']===1,'design_draft_saved');
    [$code,$public]=qa_http('/api/site-settings.php');
    qa_positive($code===200&&$public['published']===false&&$public['settings']['color_theme']==='noir','design_draft_not_public');
    [$code]=qa_http('/api/admin/design.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'publish','expected_version'=>0],$csrfAdmin);
    qa_positive($code===409,'design_stale_publish_denied');
    [$code,$published]=qa_http('/api/admin/design.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'publish','expected_version'=>1],$csrfAdmin);
    qa_positive($code===200&&$published['published_version']===1,'design_publish_explicit');
    [$code,$public]=qa_http('/api/site-settings.php');
    qa_positive($code===200&&$public['published']===true&&$public['settings']===$settings,'design_public_uses_saved_published_settings');
    [$code,$saved]=qa_http('/api/admin/design.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'save_draft','expected_version'=>1,'settings'=>array_replace($settings,['color_theme'=>'canvas'])],$csrfAdmin);
    qa_positive($code===200&&$saved['draft_version']===2,'design_second_draft_saved');
    [$code,$public]=qa_http('/api/site-settings.php');
    qa_positive($code===200&&$public['version']===1&&$public['settings']===$settings,'design_new_draft_leaves_published_unchanged');
    [$code]=qa_http('/api/admin/design.php');
    qa_positive($code===401,'design_history_private');
    [$code,$published]=qa_http('/api/admin/design.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'publish','expected_version'=>2],$csrfAdmin);
    qa_positive($code===200&&$published['published_version']===2,'design_second_publish_archives_previous');
    [$code,$published]=qa_http('/api/admin/design.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'publish','expected_version'=>2],$csrfAdmin);
    qa_positive($code===200&&$published['published_version']===2&&$published['unchanged']===true,'design_repeat_publish_no_new_version');
    [$code,$private]=qa_http('/api/admin/design.php',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&count($private['history'])===1&&$private['history'][0]['version']===1&&$private['history'][0]['settings']===$settings,'design_history_preserves_original');
    $restore=['action'=>'restore_draft','expected_version'=>2,'snapshot_id'=>$private['history'][0]['id']];
    [$code]=qa_http('/api/admin/design.php',$tokenAdmin,'__Host-dmo_admin',array_replace($restore,['expected_version'=>1]),$csrfAdmin);
    qa_positive($code===409,'design_restore_stale_draft_blocked');
    [$code]=qa_http('/api/admin/design.php',$tokenAdmin,'__Host-dmo_admin',array_replace($restore,['snapshot_id'=>PHP_INT_MAX]),$csrfAdmin);
    qa_positive($code===404,'design_unknown_snapshot_rejected');
    [$code,$restored]=qa_http('/api/admin/design.php',$tokenAdmin,'__Host-dmo_admin',$restore,$csrfAdmin);
    qa_positive($code===200&&$restored['draft_version']===3,'design_restore_creates_draft_only');
    [$code,$private]=qa_http('/api/admin/design.php',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$private['draft']['settings']===$settings,'design_restored_draft_matches_snapshot');
    [$code,$public]=qa_http('/api/site-settings.php');
    qa_positive($code===200&&$public['version']===2&&$public['settings']['color_theme']==='canvas'&&!isset($public['history']),'design_restore_does_not_change_public');
    // Real catalog endpoints: draft isolation, stale revision, publication validity.
    $item=['id'=>null,'slug'=>'ci-new-tier','name'=>'CI Portrait','description'=>'Synthetic portrait package','currency'=>'CAD','estimate_min_minor'=>1000,'estimate_max_minor'=>2000,'sort_order'=>0,'features'=>['Synthetic feature']];
    [$code]=qa_http('/api/admin/packages.php');
    qa_positive($code===401,'catalog_admin_private');
    [$code]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'save_tier','item'=>$item]);
    qa_positive($code===403,'catalog_requires_csrf');
    [$code,$new]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'save_tier','item'=>$item],$csrfAdmin);
    qa_positive($code===200,'catalog_new_draft');
    $item['id']=$new['id'];
    [$code,$catalog]=qa_http('/api/catalog.php');
    qa_positive($code===200&&!in_array($item['id'],array_column($catalog['tiers'],'id'),true),'catalog_draft_private');
    [$code,,$serviceHtml]=qa_http('/services.php');
    qa_positive($code===200&&!str_contains($serviceHtml,'CI Portrait'),'services_html_draft_hidden');
    [$code,$private]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin');
    $row=array_values(array_filter($private['catalog']['tiers'],fn($r)=>$r['id']===$item['id']))[0];
    $revision=$row['revision'];
    $publish=['action'=>'set_active','kind'=>'tier','id'=>$item['id'],'active'=>true,'expected_revision'=>$revision,'expected_compatibility_revision'=>$row['compatibility']['revision']];
    [$code]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin',array_replace($publish,['expected_revision'=>str_repeat('0',64)]),$csrfAdmin);
    qa_positive($code===409,'catalog_stale_publish_rejected');
    [$code]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin',$publish,$csrfAdmin);
    qa_positive($code===200,'catalog_explicit_publish');
    [$code,$catalog]=qa_http('/api/catalog.php');
    qa_positive($code===200&&count($catalog['tiers'])===1&&$catalog['tiers'][0]['name']===$item['name']&&!isset($catalog['tiers'][0]['revision'])&&$catalog['requests_open']===false,'catalog_public_approved_and_paused');
    [$code,,$serviceHtml]=qa_http('/services.php');
    qa_positive($code===200&&str_contains($serviceHtml,'CI Portrait')&&str_contains($serviceHtml,'CAD 10.00 – 20.00')&&str_contains($serviceHtml,'Requests temporarily paused'),'services_html_published_estimates_and_pause');
    qa_positive(!str_contains($serviceHtml,'Choose this package'),'paused_services_no_package_handoff');
    [$code,$private]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin');
    $row=array_values(array_filter($private['catalog']['tiers'],fn($r)=>$r['id']===$item['id']))[0];
    $current=$row['revision'];
    qa_positive($revision!==$current,'catalog_revision_changes_with_visibility');
    [$code]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'save_tier','expected_revision'=>$current,'item'=>array_replace($item,['description'=>''])],$csrfAdmin);
    qa_positive($code===422,'catalog_published_incomplete_edit_rejected');
    [$code]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'save_tier','expected_revision'=>$revision,'item'=>$item],$csrfAdmin);
    qa_positive($code===409,'catalog_stale_edit_rejected');
    [$code]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin',array_replace($publish,['active'=>false,'expected_revision'=>$current]),$csrfAdmin);
    qa_positive($code===200,'catalog_unpublish');
    [$code,$catalog]=qa_http('/api/catalog.php');
    qa_positive($code===200&&$catalog['tiers']===[],'catalog_unpublished_hidden');
    [$code,,$serviceHtml]=qa_http('/services.php');
    qa_positive($code===200&&!str_contains($serviceHtml,'CI Portrait'),'services_html_unpublished_hidden');
    require_once dirname(__DIR__).'/app/catalog.php';
    $db->beginTransaction();
    try{
    $db->prepare('UPDATE dmo_commission_tiers SET is_active=1 WHERE id=:id')->execute(['id'=>$item['id']]);
    qa_positive(count(dmo_catalog_public($db)['tiers'])===1,'catalog_approved_fixture_visible');
    putenv('DMO_PRICING_APPROVED=0');
    qa_positive(dmo_catalog_public($db)===['tiers'=>[],'addons'=>[]],'catalog_approval_revocation_hides_offers');
    putenv('DMO_PRICING_APPROVED=1');
    }finally{$db->rollBack();}
    // Manage restricted add-ons through the same real admin endpoint.
    $extra=['id'=>null,'slug'=>'ci-extra','name'=>'CI Extra','description'=>'Synthetic extra','currency'=>'CAD','estimate_min_minor'=>200,'estimate_max_minor'=>300,'sort_order'=>0];
    [$code,$newExtra]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'save_addon','item'=>$extra],$csrfAdmin);
    qa_positive($code===200,'catalog_extra_created');
    [$code,$private]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin');
    $row=array_values(array_filter($private['catalog']['tiers'],fn($r)=>$r['id']===$item['id']))[0];
    $extraRow=array_values(array_filter($private['catalog']['addons'],fn($r)=>$r['id']===$newExtra['id']))[0];
    [$code]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'set_active','kind'=>'addon','id'=>$extraRow['id'],'active'=>true,'expected_revision'=>$extraRow['revision']],$csrfAdmin);
    qa_positive($code===200,'catalog_extra_published');
    $restrict=['action'=>'set_compatibility','kind'=>'tier','id'=>$row['id'],'mode'=>'selected','allowed_addon_ids'=>[],'expected_revision'=>$row['revision'],'expected_compatibility_revision'=>$row['compatibility']['revision']];
    [$code]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin',array_replace($restrict,['expected_compatibility_revision'=>str_repeat('0',64)]),$csrfAdmin);
    qa_positive($code===409,'catalog_stale_compatibility_rejected');
    [$code]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin',array_replace($restrict,['allowed_addon_ids'=>[PHP_INT_MAX]]),$csrfAdmin);
    qa_positive($code===422,'catalog_unknown_extra_rejected');
    [$code]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin',$restrict,$csrfAdmin);
    qa_positive($code===200,'catalog_draft_restricts_all_extras');
    [$code,$private]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin');
    $row=array_values(array_filter($private['catalog']['tiers'],fn($r)=>$r['id']===$item['id']))[0];
    $publish=['action'=>'set_active','kind'=>'tier','id'=>$row['id'],'active'=>true,'expected_revision'=>$row['revision'],'expected_compatibility_revision'=>$row['compatibility']['revision']];
    [$code]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin',array_replace($publish,['expected_compatibility_revision'=>$restrict['expected_compatibility_revision']]),$csrfAdmin);
    qa_positive($code===409,'catalog_publish_checks_compatibility_revision');
    [$code]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin',$publish,$csrfAdmin);
    qa_positive($code===200,'catalog_restricted_tier_published');
    [$code,$catalog]=qa_http('/api/catalog.php');
    qa_positive($code===200&&$catalog['tiers'][0]['available_addon_ids']===[]&&count($catalog['addons'])===1,'catalog_restriction_public_empty_not_unrestricted');
    [$code,$private]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin');
    $row=array_values(array_filter($private['catalog']['tiers'],fn($r)=>$r['id']===$item['id']))[0];
    $restrict['expected_revision']=$row['revision'];$restrict['expected_compatibility_revision']=$row['compatibility']['revision'];
    $restrict['allowed_addon_ids']=[$extraRow['id']];
    [$code]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin',$restrict,$csrfAdmin);
    qa_positive($code===200,'catalog_live_extra_selection_saved');
    [$code,$catalog]=qa_http('/api/catalog.php');
    qa_positive($code===200&&$catalog['tiers'][0]['available_addon_ids']===[$extraRow['id']],'catalog_selected_extra_visible');
    [$code,$private]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin');
    $row=array_values(array_filter($private['catalog']['tiers'],fn($r)=>$r['id']===$item['id']))[0];
    $restrict['expected_compatibility_revision']=$row['compatibility']['revision'];$restrict['mode']='all';$restrict['allowed_addon_ids']=[];
    [$code]=qa_http('/api/admin/packages.php',$tokenAdmin,'__Host-dmo_admin',$restrict,$csrfAdmin);
    qa_positive($code===200,'catalog_unrestricted_restored');
    [$code,$catalog]=qa_http('/api/catalog.php');
    qa_positive($code===200&&array_key_exists('available_addon_ids',$catalog['tiers'][0])&&$catalog['tiers'][0]['available_addon_ids']===null,'catalog_unrestricted_public_null');
    [$code,$report]=qa_http('/api/admin/overview.php',$tokenAdmin,'__Host-dmo_admin');
    $evidence=$report['launch_evidence']??null;
    qa_positive($code===200&&$report['configuration_review']['mode']==='review_only'&&$report['configuration_review']['authorizes_launch']===false,'configuration_review_report_not_authorization');
    qa_positive($code===200&&is_array($evidence)&&$evidence['scope']==='configuration_only'&&$evidence['authorizes_launch']===false&&count($evidence['stages'])===6,'launch_configuration_report_not_authorization');
    qa_positive($evidence['published_package_count']===1&&$evidence['intake_open']===false,'launch_report_reflects_catalog_and_intake');
    foreach($evidence['stages'] as $stage)qa_positive(count($stage['external_evidence_required'])>0,'launch_stage_requires_external_evidence');
    qa_positive(!str_contains(json_encode($evidence,JSON_THROW_ON_ERROR),'positive-a@example.invalid')&&!str_contains(json_encode($evidence,JSON_THROW_ON_ERROR),$tokenAdmin),'launch_evidence_excludes_private_identity_and_credentials');
    [$code]=qa_http('/api/admin/overview.php');
    qa_positive($code===401,'launch_evidence_requires_admin');
    require_once dirname(__DIR__).'/app/admin.php';
    $db->beginTransaction();
    qa_positive(dmo_admin_login_lock($db,$admin,$hash),'admin_login_lock_accepts_verified_snapshot');
    $db->prepare('UPDATE dmo_users SET password_hash=:hash WHERE id=:id')->execute(['hash'=>password_hash('Synthetic replacement password!',PASSWORD_DEFAULT),'id'=>$admin]);
    qa_positive(!dmo_admin_login_lock($db,$admin,$hash),'admin_login_lock_rejects_replaced_password_hash');
    $db->rollBack();
    $secondAdminToken=bin2hex(random_bytes(32));
    qa_seed($db,'INSERT INTO dmo_sessions (user_id,session_hash,expires_at,last_seen_at) VALUES (:user,:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),UTC_TIMESTAMP())',['user'=>$admin,'hash'=>hash('sha256',$secondAdminToken)]);
    [$code,$security]=qa_http('/api/admin/security.php',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$security['active_sessions']===2&&!str_contains(json_encode($security,JSON_THROW_ON_ERROR),'session_hash'),'admin_security_scoped_summary');
    [$code]=qa_http('/api/admin/security.php',$tokenA,'__Host-dmo_admin');
    qa_positive($code===401,'admin_security_customer_denied');
    $revoke=['action'=>'revoke_other_sessions','current_password'=>'CI-positive-disposable-password!'];
    [$code]=qa_http('/api/admin/security.php',$tokenAdmin,'__Host-dmo_admin',$revoke);
    qa_positive($code===403,'admin_security_csrf_required');
    [$code]=qa_http('/api/admin/security.php',$tokenAdmin,'__Host-dmo_admin',array_replace($revoke,['current_password'=>'incorrect']),$csrfAdmin);
    qa_positive($code===403,'admin_security_current_password_required');
    [$code,$revoked]=qa_http('/api/admin/security.php',$tokenAdmin,'__Host-dmo_admin',$revoke,$csrfAdmin);
    qa_positive($code===200&&$revoked['sessions_revoked']===1&&$revoked['requires_sign_in']===false,'admin_security_other_session_revoked');
    [$code,$me]=qa_http('/api/admin/me.php',$secondAdminToken,'__Host-dmo_admin');
    qa_positive($code===200&&$me['authenticated']===false,'admin_revoked_session_denied');
    [$code,$me]=qa_http('/api/admin/me.php',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$me['authenticated']===true,'admin_current_session_preserved');
    $newPassword='CI-new-admin-password-123!';
    [$code,$changed]=qa_http('/api/admin/security.php',$tokenAdmin,'__Host-dmo_admin',['action'=>'change_password','current_password'=>'CI-positive-disposable-password!','new_password'=>$newPassword],$csrfAdmin);
    qa_positive($code===200&&$changed['requires_sign_in']===true,'admin_password_change_revokes_current_session');
    [$code,$me]=qa_http('/api/admin/me.php',$tokenAdmin,'__Host-dmo_admin');
    qa_positive($code===200&&$me['authenticated']===false,'admin_changed_password_session_invalid');
    $q=$db->prepare('SELECT password_hash FROM dmo_users WHERE id=:id');$q->execute(['id'=>$admin]);
    qa_positive(password_verify($newPassword,$q->fetchColumn()),'admin_password_replacement_persisted');
    echo "DISPOSABLE_POSITIVE_HTTP=PASS\n";
} catch(Throwable $error) {
    fwrite(STDERR,'DISPOSABLE_POSITIVE_HTTP=FAIL ('.get_class($error).
        ': '.$error->getMessage().")\n");
    exit(1);
} finally {
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    if(is_string($log)) @unlink($log);
}
