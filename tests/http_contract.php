<?php
declare(strict_types=1);

/**
 * Real HTTP authorization checks, but only against a disposable CI MariaDB
 * container. The temporary local server emulates HTTPS in ci_router.php.
 * No real accounts, SMTP, payments or remote service are contacted.
 */
if (PHP_SAPI !== 'cli' ||
    getenv('DMO_CI_INTEGRATION') !== '1' ||
    getenv('DB_HOST') !== '127.0.0.1' ||
    getenv('DB_NAME') !== 'drawmeone_ci' ||
    getenv('DB_USER') !== 'ci_user') {
    fwrite(STDERR, "Disposable CI database required.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/app/auth.php';

function http_check(bool $value, string $message): void
{
    if (!$value) throw new RuntimeException('HTTP_CONTRACT_FAIL=' . $message);
    echo "PASS: " . $message . "\n";
}
function http_insert(PDO $db, string $sql, array $values): int
{
    $db->prepare($sql)->execute($values);
    return (int) $db->lastInsertId();
}
function http_call(string $path, ?string $cookie = null, ?array $json = null,
    ?string $csrf = null, string $origin = 'https://drawmeone.com'): array
{
    $url = 'http://127.0.0.1:18861' . $path;
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('Local request setup failed.');
    $headers = ['Accept: application/json'];
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Origin: ' . $origin;
    }
    if ($csrf !== null) $headers[] = 'X-DMO-CSRF: ' . $csrf;
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 2000,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($cookie !== null) {
        $options[CURLOPT_COOKIE] = '__Host-dmo_session=' . $cookie;
    }
    if ($json !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($json, JSON_THROW_ON_ERROR);
    }
    $responseHeaders=[];
    $options[CURLOPT_HEADERFUNCTION]=static function($curl,string $line)use(&$responseHeaders):int{
        if(str_contains($line,':')){[$name,$value]=explode(':',$line,2);$responseHeaders[strtolower(trim($name))]=trim($value);}
        return strlen($line);
    };
    curl_setopt_array($curl, $options);
    $raw = curl_exec($curl);
    $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$code, is_string($raw) ? json_decode($raw, true) : null,$responseHeaders];
}

$process = null;
$errFile = tempnam(sys_get_temp_dir(), 'dmo-http-');
try {
    $db = dmo_database();
    $hash = password_hash('Disposable-test-password-only!', PASSWORD_DEFAULT);
    $account1 = http_insert($db,
        "INSERT INTO dmo_users (email,display_name,password_hash,role,account_status,email_verified_at)
         VALUES ('test-a@example.invalid','Test A',:hash,'customer','active',UTC_TIMESTAMP())",
        ['hash'=>$hash]);
    $account2 = http_insert($db,
        "INSERT INTO dmo_users (email,display_name,password_hash,role,account_status,email_verified_at)
         VALUES ('test-b@example.invalid','Test B',:hash,'customer','active',UTC_TIMESTAMP())",
        ['hash'=>$hash]);
    $artist = http_insert($db,
        "INSERT INTO dmo_users (email,display_name,password_hash,role,account_status,email_verified_at)
         VALUES ('test-artist@example.invalid','Test Artist',:hash,'admin','active',UTC_TIMESTAMP())",
        ['hash'=>$hash]);
    $ref1 = 'DMO-' . substr(hash('sha256','http-ci-customer-a'),0,20);
    $ref2 = 'DMO-' . substr(hash('sha256','http-ci-customer-b'),0,20);
    $request1 = http_insert($db,
        "INSERT INTO dmo_commission_requests
         (public_ref,customer_id,subject_type,brief,preferred_contact,policy_version,status)
         VALUES (:ref,:customer,'portrait',:brief,'onsite','qa-policy-v1','submitted')",
        ['ref'=>$ref1,'customer'=>$account1,
         'brief'=>str_repeat('Commission details for private test A. ',2)]);
    $request2 = http_insert($db,
        "INSERT INTO dmo_commission_requests
         (public_ref,customer_id,subject_type,brief,preferred_contact,policy_version,status)
         VALUES (:ref,:customer,'portrait',:brief,'onsite','qa-policy-v1','submitted')",
        ['ref'=>$ref2,'customer'=>$account2,
         'brief'=>str_repeat('Commission details for private test B. ',2)]);
    $conversation = http_insert($db,
        'INSERT INTO dmo_conversations (request_id) VALUES (:request)',
        ['request'=>$request1]);
    http_insert($db,
        "INSERT INTO dmo_messages (conversation_id,sender_id,body,is_internal)
         VALUES (:conversation,:sender,'CI SECRET ARTIST NOTE',1)",
        ['conversation'=>$conversation,'sender'=>$artist]);
    http_insert($db,
        "INSERT INTO dmo_messages (conversation_id,sender_id,body,is_internal)
         VALUES (:conversation,:sender,'CI customer-visible conversation',0)",
        ['conversation'=>$conversation,'sender'=>$account1]);
    $draft = http_insert($db,
        "INSERT INTO dmo_quotes
         (request_id,version_number,currency,total_minor,scope_description,revision_allowance,status)
         VALUES (:request,1,'CAD',1900,'CI hidden draft',1,'draft')",
        ['request'=>$request1]);
    $sent = http_insert($db,
        "INSERT INTO dmo_quotes
         (request_id,version_number,currency,total_minor,scope_description,
          revision_allowance,status,sent_at,validity_ends_at,accepted_at)
         VALUES (:request,2,'CAD',2100,'CI accepted private quote',1,'accepted',
          UTC_TIMESTAMP(),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 DAY),UTC_TIMESTAMP())",
        ['request'=>$request1]);
    foreach ([[$draft,1900],[$sent,2100]] as [$quote,$cents]) {
        http_insert($db,
            "INSERT INTO dmo_quote_lines (quote_id,description,amount_minor,sort_order)
             VALUES (:quote,'CI line',:amount,0)",
            ['quote'=>$quote,'amount'=>$cents]);
    }
    http_insert($db,
        "INSERT INTO dmo_orders
         (request_id,accepted_quote_id,status,payment_status)
         VALUES (:request,:quote,'awaiting_arrangement','unverified')",
        ['request'=>$request1,'quote'=>$sent]);

    $cookieA = bin2hex(random_bytes(32));
    $cookieB = bin2hex(random_bytes(32));
    foreach ([[$account1,$cookieA],[$account2,$cookieB]] as [$user,$cookie]) {
        http_insert($db,
            'INSERT INTO dmo_sessions (user_id,session_hash,expires_at,last_seen_at) ' .
            'VALUES (:user,:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR),UTC_TIMESTAMP())',
            ['user'=>$user,'hash'=>hash('sha256',$cookie)]);
    }
    $csrfA = hash_hmac('sha256', 'dmo-csrf|' . hash('sha256',$cookieA),
        dmo_config('DMO_APP_KEY'));

    // The disposable server inherits only CI values, never Hostinger config.
    putenv('DMO_AUTH_ENABLED=1');
    putenv('DMO_MAIL_ENABLED=1');
    putenv('DMO_MAIL_TRANSPORT=php');
    putenv('DMO_ALLOW_PHP_MAIL=1');
    putenv('DMO_MAIL_FROM=support@drawmeone.com');
    putenv('DMO_TURNSTILE_SITE_KEY=ci-test-placeholder');
    putenv('DMO_TURNSTILE_SECRET=ci-test-placeholder');
    putenv('DMO_ADMIN_ENABLED=1');
    putenv('DMO_REGISTRATION_ENABLED=0');
    putenv('DMO_CUSTOMER_MESSAGING_ENABLED=0');
    putenv('DMO_QUOTE_RESPONSES_ENABLED=0');
    putenv('DMO_ORDER_ADMIN_ENABLED=0');

    if (!is_string($errFile)) throw new RuntimeException('Local log unavailable.');
    $server = [
        PHP_BINARY,'-S','127.0.0.1:18861',
        '-t',dirname(__DIR__),__DIR__ . '/ci_router.php',
    ];
    $process = proc_open($server, [
        0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file',$errFile,'w'],
    ], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Local web server could not start.');
    if (isset($pipes[0])) fclose($pipes[0]);
    $running = false;
    for ($i=0;$i<35;$i++) {
        [$status] = http_call('/api/request.php?ref=' . urlencode($ref1));
        if ($status !== 0) { $running = true; break; }
        usleep(100000);
    }
    http_check($running, 'local_server_ready');

    [$status] = http_call('/api/request.php?ref=' . urlencode($ref1));
    http_check($status === 401, 'anonymous_customer_detail_rejected');
    [$status,$own,$headers] = http_call('/api/request.php?ref=' . urlencode($ref1),$cookieA);
    http_check(str_contains($headers['cache-control']??'','no-store')&&str_contains($headers['cache-control']??'','private')&&($headers['x-content-type-options']??'')==='nosniff'&&($headers['referrer-policy']??'')==='no-referrer'&&($headers['x-frame-options']??'')==='DENY','private_request_security_headers');
    http_check($status === 200 && ($own['ok'] ?? false) &&
        ($own['request']['public_ref'] ?? '') === $ref1,
        'verified_customer_reads_own_request');
    http_check(count($own['messages'] ?? []) === 1 &&
        ($own['messages'][0]['body'] ?? '') === 'CI customer-visible conversation',
        'private_artist_note_hidden_over_http');
    http_check(count($own['quotes'] ?? []) === 1 &&
        ($own['quotes'][0]['id'] ?? null) === $sent &&
        ($own['quotes'][0]['total_minor'] ?? null) === 2100,
        'draft_quote_hidden_over_http');
    http_check(count($own['orders'] ?? []) === 1 &&
        ($own['orders'][0]['payment_status'] ?? null) === 'unverified',
        'unpaid_order_only_for_owner');

    [$status] = http_call('/api/request.php?ref=' . urlencode($ref1),$cookieB);
    http_check($status === 404,'cross_customer_detail_rejected');
    [$status,$ownB] = http_call('/api/request.php?ref=' . urlencode($ref2),$cookieB);
    http_check($status === 200 && ($ownB['request']['public_ref'] ?? '') === $ref2 &&
        count($ownB['quotes'] ?? []) === 0 && count($ownB['orders'] ?? []) === 0,
        'second_customer_data_isolated');

    [$status] = http_call('/api/admin/overview.php');
    http_check($status === 401,'unauthenticated_artist_dashboard_rejected');
    foreach(['/api/admin/finance.php','/api/admin/customers.php','/api/admin/privacy.php','/api/admin/artworks.php'] as $path){
        [$status,,$headers]=http_call($path);
        http_check($status===401&&str_contains($headers['cache-control']??'','no-store')&&($headers['x-robots-tag']??'')==='noindex, nofollow','private_admin_route_denied_and_uncacheable');
    }

    $message = ['ref'=>$ref1,'body'=>'CI test message should not persist'];
    [$status] = http_call('/api/request-message.php',$cookieA,$message);
    http_check($status === 403,'missing_csrf_rejected');
    [$status] = http_call('/api/request-message.php',$cookieA,$message,$csrfA,
        'https://evil.example.invalid');
    http_check($status === 403,'wrong_origin_rejected');
    [$status] = http_call('/api/request-message.php',$cookieA,$message,$csrfA);
    http_check($status === 503,'disabled_messaging_fails_closed');

    [$status] = http_call('/api/quote-response.php',$cookieA,[
        'ref'=>$ref1,'quote_id'=>$sent,'decision'=>'decline',
        'policy_version'=>'qa-policy-v1','acknowledge_quote'=>false,
    ],$csrfA);
    http_check($status === 503,'disabled_quote_responses_fail_closed');

    [$status] = http_call('/api/admin/order-create.php',null,[
        'request_id'=>$request1,'accepted_quote_id'=>$sent,
        'confirm_unpaid'=>true,
    ]);
    http_check($status === 401,'unauthenticated_order_creation_rejected');
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM dmo_messages WHERE conversation_id=:conversation');
    $statement->execute(['conversation'=>$conversation]);
    http_check((int)$statement->fetchColumn() === 2,
        'rejected_messages_not_persisted');
    http_check((int)$db->query('SELECT COUNT(*) FROM dmo_payments')->fetchColumn() === 0,
        'http_contract_never_collected_payment');
    echo "DISPOSABLE_HTTP=PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR,"DISPOSABLE_HTTP=FAIL (" . get_class($error) . ': ' .
        $error->getMessage() . ")\n");
    exit(1);
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
    if (is_string($errFile)) @unlink($errFile);
}
