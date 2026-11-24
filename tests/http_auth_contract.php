<?php
declare(strict_types=1);

/**
 * Verify REAL token consumption / expiry / device invalidation via actual
 * HTTP routes against a disposable local-only MariaDB service.
 * Does not send email or make external CAPTCHA requests.
 */
if (PHP_SAPI !== 'cli' ||
    getenv('DMO_CI_INTEGRATION') !== '1' ||
    getenv('DB_HOST') !== '127.0.0.1' ||
    getenv('DB_NAME') !== 'drawmeone_ci' ||
    getenv('DB_USER') !== 'ci_user') {
    fwrite(STDERR, "Refusing non-disposable auth test.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/app/auth.php';
function auth_check(bool $condition, string $name): void
{
    if (!$condition) throw new RuntimeException('AUTH_TEST_FAILED=' . $name);
    echo "PASS: " . $name . "\n";
}
function auth_insert(PDO $db,string $sql,array $args): int
{
    $db->prepare($sql)->execute($args);
    return (int)$db->lastInsertId();
}
function auth_request(string $path,?array $payload=null,?string $cookie=null,
    string $origin='https://drawmeone.com'): array
{
    $ch=curl_init('http://127.0.0.1:18863'.$path);
    if ($ch===false) throw new RuntimeException('HTTP test client unavailable');
    $options=[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT_MS=>2000,
        CURLOPT_TIMEOUT=>7,
    ];
    $headers=['Accept: application/json'];
    if($cookie!==null) $options[CURLOPT_COOKIE]='__Host-dmo_session='.$cookie;
    if($payload!==null){
        $options[CURLOPT_POST]=true;
        $options[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_THROW_ON_ERROR);
        $headers[]='Content-Type: application/json';
        $headers[]='Origin: '.$origin;
    }
    $options[CURLOPT_HTTPHEADER]=$headers;
    curl_setopt_array($ch,$options);
    $result=curl_exec($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code,is_string($result)?json_decode($result,true):null];
}
function auth_reset_token(PDO $db,int $user,string $raw): int
{
    return auth_insert($db,
        "INSERT INTO dmo_auth_tokens (user_id,purpose,token_hash,expires_at)
         VALUES (:id,'reset_password',:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))",
        ['id'=>$user,'hash'=>hash('sha256',$raw)]);
}
function auth_session(PDO $db,int $user): string
{
    $token=bin2hex(random_bytes(32));
    auth_insert($db,
        "INSERT INTO dmo_sessions (user_id,session_hash,expires_at,last_seen_at)
         VALUES (:id,:hash,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 2 HOUR),UTC_TIMESTAMP())",
        ['id'=>$user,'hash'=>hash('sha256',$token)]);
    return $token;
}
$server=null;
$log=tempnam(sys_get_temp_dir(),'dmo-auth-ci-');
try {
    $db=dmo_database();
    $initialPassword='Original-CI-only-password-2026';
    $hash=password_hash($initialPassword,PASSWORD_DEFAULT);
    $pending=auth_insert($db,
        "INSERT INTO dmo_users
         (display_name,email,password_hash,role,account_status)
         VALUES ('Pending CI user','pending-ci@example.invalid',:hash,'customer','pending_verification')",
        ['hash'=>$hash]);
    $other=auth_insert($db,
        "INSERT INTO dmo_users
         (display_name,email,password_hash,role,account_status,email_verified_at)
         VALUES ('Other CI user','other-ci@example.invalid',:hash,'customer','active',UTC_TIMESTAMP())",
        ['hash'=>$hash]);
    $admin=auth_insert($db,
        "INSERT INTO dmo_users
         (display_name,email,password_hash,role,account_status,email_verified_at)
         VALUES ('Admin CI user','admin-ci@example.invalid',:hash,'admin','active',UTC_TIMESTAMP())",
        ['hash'=>$hash]);
    $adminReset=bin2hex(random_bytes(32));
    auth_reset_token($db,$admin,$adminReset);
        $verifyToken=dmo_auth_make_verification($db,$pending);
    $expiredToken=dmo_auth_make_verification($db,$pending);
    $db->prepare(
        "UPDATE dmo_auth_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)
         WHERE token_hash=:hash"
    )->execute(['hash'=>hash('sha256',$expiredToken)]);
    $session1=auth_session($db,$pending);
    $session2=auth_session($db,$pending);
    $otherSession=auth_session($db,$other);

    // This config is inherited ONLY by the temporary 127.0.0.1 CLI server;
    // production source is unmodified and there is no network mail delivery.
    foreach ([
        'DMO_AUTH_ENABLED'=>'1',
        'DMO_REGISTRATION_ENABLED'=>'0',
        'DMO_MAIL_ENABLED'=>'1',
        'DMO_MAIL_TRANSPORT'=>'php',
        'DMO_ALLOW_PHP_MAIL'=>'1',
        'DMO_MAIL_FROM'=>'support@drawmeone.com',
        'DMO_TURNSTILE_SITE_KEY'=>'ci-local-only',
        'DMO_TURNSTILE_SECRET'=>'ci-local-only',
    ] as $name=>$value) putenv($name.'='.$value);
    if(!is_string($log))throw new RuntimeException('Cannot create local log');
    $server=proc_open([
        PHP_BINARY,'-S','127.0.0.1:18863','-t',dirname(__DIR__),
        __DIR__.'/ci_router.php',
    ],[0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file',$log,'w']],$pipes);
    if(!is_resource($server))throw new RuntimeException('No local server');
    if(isset($pipes[0]))fclose($pipes[0]);
    $ready=false;
    for($i=0;$i<35;$i++){
        [$status]=auth_request('/api/auth/me.php');
        if($status!==0){$ready=true;break;}
        usleep(100000);
    }
    auth_check($ready,'localhost_auth_server_ready');
    [$code,$me]=auth_request('/api/auth/me.php',null,$session1);
    auth_check($code===200 && ($me['authenticated']??true)===false,
        'pending_email_cannot_authenticate');

    [$code]=auth_request('/api/auth/verify.php',['token'=>'not-a-valid-token']);
    auth_check($code===422,'invalid_verification_token_rejected');
    [$code]=auth_request('/api/auth/verify.php',['token'=>$verifyToken],null,
        'https://not-the-studio.invalid');
    auth_check($code===403,'verification_wrong_origin_rejected');
    [$code]=auth_request('/api/auth/verify.php',['token'=>$expiredToken]);
    auth_check($code===422,'expired_verification_token_rejected');
    [$code,$verified]=auth_request('/api/auth/verify.php',['token'=>$verifyToken]);
    auth_check($code===200 && ($verified['status']??null)==='email_verified',
        'actual_email_token_activates_account');
    [$code]=auth_request('/api/auth/verify.php',['token'=>$verifyToken]);
    auth_check($code===422,'verification_token_replay_rejected');
    $statement=$db->prepare(
        "SELECT account_status,email_verified_at FROM dmo_users WHERE id=:id");
    $statement->execute(['id'=>$pending]);
    $row=$statement->fetch(PDO::FETCH_ASSOC);
    auth_check(is_array($row) && $row['account_status']==='active' &&
        $row['email_verified_at']!==null,'email_verified_persisted_once');
    [$code,$me]=auth_request('/api/auth/me.php',null,$session1);
    auth_check($code===200 && ($me['authenticated']??false)===true &&
        !isset($me['user']['password_hash']) &&
        is_string($me['csrf_token']??null),'verified_customer_session_valid');

    $newPassword='Another-CI-only-password-2026';
    $expiredReset=bin2hex(random_bytes(32));
    $resetOne=bin2hex(random_bytes(32));
    $resetTwo=bin2hex(random_bytes(32));
    $resetExpiredId=auth_reset_token($db,$pending,$expiredReset);
    auth_reset_token($db,$pending,$resetOne);
    auth_reset_token($db,$pending,$resetTwo);
    $db->prepare(
        "UPDATE dmo_auth_tokens SET expires_at=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 MINUTE)
         WHERE id=:id"
    )->execute(['id'=>$resetExpiredId]);
    [$code]=auth_request('/api/auth/reset-confirm.php',
        ['token'=>$adminReset,'password'=>$newPassword]);
    auth_check($code===422,'admin_role_not_resettable_through_customer_endpoint');
        [$code]=auth_request('/api/auth/reset-confirm.php',
        ['token'=>$expiredReset,'password'=>$newPassword]);
    auth_check($code===422,'expired_password_reset_rejected');
    [$code]=auth_request('/api/auth/reset-confirm.php',
        ['token'=>$resetOne,'password'=>'short']);
    auth_check($code===422,'weak_new_password_rejected');
    [$code]=auth_request('/api/auth/reset-confirm.php',
        ['token'=>$resetOne,'password'=>$newPassword],null,'https://evil.invalid');
    auth_check($code===403,'password_reset_wrong_origin_rejected');
    [$code,$reset]=auth_request('/api/auth/reset-confirm.php',
        ['token'=>$resetOne,'password'=>$newPassword]);
    auth_check($code===200 && ($reset['status']??'')==='password_updated',
        'valid_reset_changes_password');
    [$code]=auth_request('/api/auth/reset-confirm.php',
        ['token'=>$resetOne,'password'=>$newPassword]);
    auth_check($code===422,'password_reset_token_replay_rejected');
    [$code]=auth_request('/api/auth/reset-confirm.php',
        ['token'=>$resetTwo,'password'=>$newPassword]);
    auth_check($code===422,'parallel_reset_token_invalidated');

    $stmt=$db->prepare('SELECT password_hash FROM dmo_users WHERE id=:id');
    $stmt->execute(['id'=>$pending]);
    $newHash=$stmt->fetchColumn();
    auth_check(is_string($newHash) && password_verify($newPassword,$newHash) &&
        !password_verify($initialPassword,$newHash),'password_hash_rotated');

    [$code,$me]=auth_request('/api/auth/me.php',null,$session1);
    auth_check($code===200 && ($me['authenticated']??true)===false,
        'first_existing_device_logged_out');
    [$code,$me]=auth_request('/api/auth/me.php',null,$session2);
    auth_check($code===200 && ($me['authenticated']??true)===false,
        'second_existing_device_logged_out');
    [$code,$me]=auth_request('/api/auth/me.php',null,$otherSession);
    auth_check($code===200 && ($me['authenticated']??false)===true,
        'other_customer_session_preserved');
    $stmt=$db->prepare(
        "SELECT COUNT(*) FROM dmo_auth_tokens WHERE user_id=:id AND
         purpose='reset_password' AND consumed_at IS NULL");
    $stmt->execute(['id'=>$pending]);
    auth_check((int)$stmt->fetchColumn()===0,
        'all_outstanding_password_reset_links_consumed');
    auth_check((int)$db->query('SELECT COUNT(*) FROM dmo_payments')->fetchColumn()===0,
        'auth_tests_created_no_payments');
    echo "DISPOSABLE_AUTH_HTTP=PASS\n";
} catch(Throwable $error) {
    fwrite(STDERR,'DISPOSABLE_AUTH_HTTP=FAIL ('.get_class($error).
        ': '.$error->getMessage().")\n");
    exit(1);
} finally {
    if(is_resource($server)){proc_terminate($server);proc_close($server);}
    if(is_string($log))@unlink($log);
}
