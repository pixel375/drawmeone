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
        'DMO_MANUAL_PAYMENT_ENABLED'=>'1','DMO_MANUAL_PAYMENT_TERMS_APPROVED'=>'1',
        'DMO_REGISTRATION_ENABLED'=>'0','DMO_REQUESTS_ENABLED'=>'1','DMO_INTAKE_CONTROL_ENABLED'=>'1','DMO_CHECKOUT_LINKS_ENABLED'=>'1','DMO_CHECKOUT_TERMS_APPROVED'=>'1','DMO_CHECKOUT_HOST'=>'checkout.example.invalid','DMO_CHECKOUT_PATH_PREFIX'=>'/invoice/','DMO_PRIVACY_SELF_SERVICE_ENABLED'=>'1',
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
    [$code,$archived]=qa_http('/api/policy.php?version=qa-policy-v0&sha256='.$oldPin);
    qa_positive($code===200&&$archived['policy']['version']==='qa-policy-v0','explicitly_pinned_historical_policy_readable');
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
    $oldQuote=$quoteId;
    $db->prepare('UPDATE dmo_quotes SET validity_ends_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY) WHERE id=:id')->execute(['id'=>$oldQuote]);
    [$code,$expired]=qa_http('/api/request.php?ref='.urlencode($referenceA),$tokenA);
    qa_positive($code===200 && $expired['request']['workflow_stage']==='quote_expired' &&
        $expired['quotes'][0]['can_respond']===false,'expired_offer_not_actionable');
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
    $q=$db->prepare('SELECT url_ciphertext FROM dmo_checkout_links WHERE id=:id');$q->execute(['id'=>$linkId]);
    qa_positive(!str_contains((string)$q->fetchColumn(),'ci-first'),'checkout_url_encrypted_at_rest');
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
    echo "DISPOSABLE_POSITIVE_HTTP=PASS\n";
} catch(Throwable $error) {
    fwrite(STDERR,'DISPOSABLE_POSITIVE_HTTP=FAIL ('.get_class($error).
        ': '.$error->getMessage().")\n");
    exit(1);
} finally {
    if(is_resource($process)){proc_terminate($process);proc_close($process);}
    if(is_string($log)) @unlink($log);
}
