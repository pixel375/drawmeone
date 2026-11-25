<?php
declare(strict_types=1);
/**
 * Complete, independent recovery drill against synthetic data and two
 * disposable databases. NEVER run against any external database or real
 * Hostinger backup. No credentials, plaintext archives or recovery key leave CI.
 */
if (PHP_SAPI !== 'cli' ||
    getenv('DMO_CI_INTEGRATION') !== '1' ||
    getenv('DB_HOST') !== '127.0.0.1' ||
    getenv('DB_NAME') !== 'drawmeone_ci' ||
    getenv('DB_USER') !== 'ci_user' ||
    getenv('DB_PASS') !== 'ephemeral_ci_password_only') {
    fwrite(STDERR, "Refusing a non-disposable backup recovery drill.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/app/artwork.php';
require_once dirname(__DIR__) . '/app/references.php';
require_once dirname(__DIR__).'/app/integrity.php';

function drill_assert(bool $condition, string $name): void
{
    if (!$condition) throw new RuntimeException('RECOVERY_TEST_FAIL=' . $name);
    echo "PASS: " . $name . "\n";
}
function drill_exec(array $argv, ?string $stdinFile = null): void
{
    $spec = [
        0 => $stdinFile === null ? ['pipe', 'r'] : ['file', $stdinFile, 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ];
    $process = proc_open($argv, $spec, $pipes, null, null, ['bypass_shell'=>true]);
    if (!is_resource($process)) throw new RuntimeException('Cannot start isolated utility');
    if ($stdinFile === null && isset($pipes[0])) fclose($pipes[0]);
    if (proc_close($process) !== 0) throw new RuntimeException('Isolated command failed');
}
function drill_decrypt(string $encrypted, string $plain, string $key): void
{
    $input = fopen($encrypted, 'rb');
    $output = fopen($plain, 'xb');
    if (!is_resource($input) || !is_resource($output)) {
        throw new RuntimeException('Cannot start isolated decrypt');
    }
    try {
        if (fread($input, 6) !== 'DMOBK2') throw new RuntimeException('Incorrect backup version');
        $header = fread($input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        if (!is_string($header) ||
            strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
            throw new RuntimeException('Truncated backup header');
        }
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        $final = false;
        while (!$final) {
            $four = fread($input, 4);
            if (!is_string($four) || strlen($four) !== 4) {
                throw new RuntimeException('Truncated encrypted backup frame');
            }
            $size = unpack('Nvalue', $four)['value'];
            if ($size < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES ||
                $size > 1024 * 1024 + 256) {
                throw new RuntimeException('Invalid backup frame length');
            }
            $ciphertext = '';
            while (strlen($ciphertext) < $size) {
                $part = fread($input, $size - strlen($ciphertext));
                if (!is_string($part) || $part === '') {
                    throw new RuntimeException('Incomplete encrypted frame');
                }
                $ciphertext .= $part;
            }
            $decoded = sodium_crypto_secretstream_xchacha20poly1305_pull($state,$ciphertext);
            if (!is_array($decoded)) throw new RuntimeException('Encrypted frame failed auth');
            $bytes = $decoded[0];
            $position = 0;
            while ($position < strlen($bytes)) {
                $amount = fwrite($output, substr($bytes, $position));
                if (!is_int($amount) || $amount < 1) throw new RuntimeException('Restore write failed');
                $position += $amount;
            }
            $final = $decoded[1] === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }
        if (fread($input, 1) !== '') throw new RuntimeException('Trailing encrypted bytes');
        fflush($output);
    } finally {
        fclose($input);
        fclose($output);
    }
}
function drill_cleanup(string $path): void
{
    if (!is_dir($path) || is_link($path)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
        else @unlink($item->getPathname());
    }
    @rmdir($path);
}

$workspace = sys_get_temp_dir() . '/dmo-full-restore-ci-' . bin2hex(random_bytes(8));
try {
    drill_assert(extension_loaded('sodium') && extension_loaded('gd') &&
        function_exists('imagewebp'),'backup_crypto_and_image_processing_available');
    if (!mkdir($workspace, 0700)) throw new RuntimeException('CI workspace creation failed');
    $env = $workspace . '/private.env';
    file_put_contents($env, "# Disposable CI snapshot, never production\n");
    chmod($env,0600);
    putenv('DMO_ENV_FILE=' . $env);
    $appKey=bin2hex(random_bytes(32));
    putenv('DMO_APP_KEY='.$appKey);
    $db = dmo_database();
    $key = bin2hex(random_bytes(16));
    $artRoot = $workspace . '/drawmeone-artworks';
    $refRoot = $workspace . '/drawmeone-references';
    mkdir($artRoot,0700);
    mkdir($refRoot,0700);
    mkdir($artRoot . '/' . $key,0700);
    $refKey = bin2hex(random_bytes(16));
    mkdir($refRoot . '/' . $refKey,0700);
    $im = imagecreatetruecolor(320, 240);
    if (!$im instanceof GdImage) throw new RuntimeException('Synthetic image allocation failed');
    $background=imagecolorallocate($im, 80, 140, 190);
    imagefilledrectangle($im,0,0,319,239,$background);
    $thumb = $artRoot . '/' . $key . '/thumb.webp';
    $display = $artRoot . '/' . $key . '/display.webp';
    $refThumb = $refRoot . '/' . $refKey . '/thumb.webp';
    $refDisplay = $refRoot . '/' . $refKey . '/display.webp';
    foreach ([$thumb, $display, $refThumb, $refDisplay] as $file) {
        if (!imagewebp($im,$file,85) || filesize($file) < 100) {
            throw new RuntimeException('Synthetic WebP failed');
        }
        chmod($file,0600);
    }
    $deliveryKey=bin2hex(random_bytes(16));
    $deliveryRoot=$workspace.'/drawmeone-deliveries';
    mkdir($deliveryRoot,0700);mkdir($deliveryRoot.'/'.$deliveryKey,0700);
    $deliveryFile=$deliveryRoot.'/'.$deliveryKey.'/display.webp';
    if(!imagewebp($im,$deliveryFile,95))throw new RuntimeException('Synthetic delivery image failed');
    chmod($deliveryFile,0600);
    imagedestroy($im);
    $db->prepare("INSERT INTO dmo_portfolio_artworks (slug,visibility)
                  VALUES (:slug,'published')")
       ->execute(['slug'=>'ci-recovery-' . $key]);
    $artId = (int)$db->lastInsertId();
    $insertFile = $db->prepare(
        "INSERT INTO dmo_artwork_files
         (artwork_id,variant,storage_key,mime_type,pixel_width,pixel_height,byte_size)
         VALUES (:id,:variant,:key,'image/webp',320,240,:bytes)"
    );
    foreach (['thumb'=>$thumb,'display'=>$display] as $variant=>$file) {
        $insertFile->execute([
            'id'=>$artId,'variant'=>$variant,'key'=>$key.'/'.$variant.'.webp',
            'bytes'=>filesize($file)
        ]);
    }
    $userHash=password_hash('CI-only-restore-fixture-password',PASSWORD_DEFAULT);
    $db->prepare(
        "INSERT INTO dmo_users
         (display_name,email,password_hash,role,account_status,email_verified_at)
         VALUES ('CI Recovery Customer','recovery@example.invalid',:hash,'customer','active',UTC_TIMESTAMP())"
    )->execute(['hash'=>$userHash]);
    $userId=(int)$db->lastInsertId();
    $reference='DMO-' . substr(hash('sha256',$refKey),0,20);
    $db->prepare(
        "INSERT INTO dmo_commission_requests
         (public_ref,customer_id,subject_type,brief,preferred_contact,policy_version,status)
         VALUES (:ref,:user,'portrait',:brief,'onsite','ci-v1','submitted')"
    )->execute(['ref'=>$reference,'user'=>$userId,
        'brief'=>str_repeat('Synthetic private recovery reference.',2)]);
    $requestId=(int)$db->lastInsertId();
    $db->prepare(
        "INSERT INTO dmo_request_attachments
         (request_id,uploaded_by,storage_key,mime_type,byte_size,pixel_width,pixel_height)
         VALUES (:request,:user,:key,'image/webp',:bytes,320,240)"
    )->execute([
        'request'=>$requestId,'user'=>$userId,'key'=>$refKey.'/display.webp',
        'bytes'=>filesize($refDisplay)
    ]);
    $db->prepare("INSERT INTO dmo_users (display_name,email,password_hash,role,account_status,email_verified_at) VALUES ('CI Recovery Artist','recovery-artist@example.invalid',:hash,'admin','active',UTC_TIMESTAMP())")->execute(['hash'=>$userHash]);
    $artistId=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO dmo_quotes (request_id,version_number,currency,total_minor,scope_description,revision_allowance,status,sent_at,accepted_at) VALUES (:request,1,'CAD',3000,'Synthetic recovery artwork',1,'accepted',UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute(['request'=>$requestId]);
    $quoteId=(int)$db->lastInsertId();
    $db->prepare("INSERT INTO dmo_orders (request_id,accepted_quote_id,status,payment_status) VALUES (:request,:quote,'awaiting_arrangement','unverified')")->execute(['request'=>$requestId,'quote'=>$quoteId]);
    $orderId=(int)$db->lastInsertId();
    $invoiceUrl='https://checkout.example.invalid/invoice/'.bin2hex(random_bytes(16));
    $invoiceCipher=dmo_checkout_seal($invoiceUrl,$orderId);
    $db->prepare("INSERT INTO dmo_checkout_links (order_id,created_by,url_ciphertext,url_fingerprint,provider_host,currency,amount_minor,expires_at) VALUES (:order,:actor,:cipher,:fingerprint,'checkout.example.invalid','CAD',3000,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY))")->execute(['order'=>$orderId,'actor'=>$artistId,'cipher'=>$invoiceCipher,'fingerprint'=>hash_hmac('sha256',$invoiceUrl,dmo_checkout_key())]);
    $db->prepare('INSERT INTO dmo_delivery_drafts (request_id,order_id,uploaded_by,storage_key,pixel_width,pixel_height,byte_size,sha256) VALUES (:request,:order,:actor,:key,320,240,:bytes,:sha)')->execute(['request'=>$requestId,'order'=>$orderId,'actor'=>$artistId,'key'=>$deliveryKey.'/display.webp','bytes'=>filesize($deliveryFile),'sha'=>hash_file('sha256',$deliveryFile)]);
    drill_assert((int)$db->query('SELECT COUNT(*) FROM dmo_portfolio_artworks')
        ->fetchColumn()===1,'snapshot_contains_synthetic_artwork');
    drill_assert((int)$db->query('SELECT COUNT(*) FROM dmo_request_attachments')
        ->fetchColumn()===1,'snapshot_contains_synthetic_private_reference');
    $script = dirname(__DIR__) . '/app/cli-backup.php';
    drill_exec([PHP_BINARY,$script,'run']);
    drill_exec([PHP_BINARY,$script,'verify-latest']);
    drill_exec([PHP_BINARY,$script,'restore-check']);
    drill_assert(true,'real_cli_backup_encrypt_verify_and_isolated_archive_check');
    $candidates=glob($workspace.'/drawmeone-encrypted-backups/dmo-*.bak') ?: [];
    drill_assert(count($candidates)===1 && filesize($candidates[0])>512,
        'one_real_encrypted_snapshot_created');
    // A damaged private WebP in a candidate archive must not evict or
    // overwrite the verified previous snapshot. Deliberately corrupt only
    // the temporary CI fixture; the real source code path is still used.
    $beforeHash = hash_file('sha256', $candidates[0]);
    $previousThumb = file_get_contents($refThumb);
    if (!is_string($beforeHash) || !is_string($previousThumb)) {
        throw new RuntimeException('Snapshot corruption test preflight failed');
    }
    $rejected = false;
    try {
        file_put_contents($refThumb, 'CI intentionally invalid WebP bytes');
        try {
            drill_exec([PHP_BINARY,$script,'run']);
        } catch (RuntimeException $expectedError) {
            $rejected = true;
        }
    } finally {
        file_put_contents($refThumb, $previousThumb);
    }
    $retained = glob($workspace.'/drawmeone-encrypted-backups/dmo-*.bak') ?: [];
    drill_assert($rejected && count($retained)===1 &&
        hash_file('sha256',$retained[0])===$beforeHash,
        'invalid_candidate_cannot_replace_verified_backup');
    drill_exec([PHP_BINARY,$script,'verify-latest']);
    drill_exec([PHP_BINARY,$script,'restore-check']);
    $cipherExport=$workspace.'/ciphertext-only-export.bak';
    $process=proc_open([PHP_BINARY,$script,'export-latest'],
        [0=>['pipe','r'],1=>['file',$cipherExport,'w'],
         2=>['file','/dev/null','w']],$pipes,null,null,
        ['bypass_shell'=>true]);
    if(!is_resource($process)) throw new RuntimeException('Cannot run CI encrypted export');
    if(isset($pipes[0])) fclose($pipes[0]);
    $exportExit=proc_close($process);
    drill_assert($exportExit===0 && is_file($cipherExport) &&
        hash_file('sha256',$cipherExport)===hash_file('sha256',$candidates[0]) &&
        substr((string)file_get_contents($cipherExport,false,null,0,6),0,6)==='DMOBK2',
        'verified_export_contains_exact_ciphertext_only');
    // Exercise the exact disconnected operator CLI on the exported bytes.
    // No database credentials or app bootstrap are required by this command.
    $offlineScript=dirname(__DIR__).'/app/cli-recovery-check.php';
    $offlineScratch=$workspace.'/offline-private';
    mkdir($offlineScratch,0700);
    chmod($cipherExport,0600);
    $keyPath=$workspace.'/drawmeone-backup-recovery.key';
    drill_exec([PHP_BINARY,$offlineScript,$cipherExport,$keyPath,$offlineScratch]);
    drill_assert(count(scandir($offlineScratch))===2,'offline_verified_export_and_scratch_cleanup');
    $wrongKey=$workspace.'/wrong-recovery.key';
    file_put_contents($wrongKey,random_bytes(32));chmod($wrongKey,0600);
    $trailing=$workspace.'/trailing.bak';
    copy($cipherExport,$trailing);chmod($trailing,0600);
    file_put_contents($trailing,'unexpected',FILE_APPEND);
    $truncated=$workspace.'/truncated.bak';
    copy($cipherExport,$truncated);chmod($truncated,0600);
    $fh=fopen($truncated,'r+b');
    ftruncate($fh,filesize($truncated)-1);fclose($fh);
    $publicScratch=$workspace.'/unsafe-scratch';
    mkdir($publicScratch,0755);chmod($publicScratch,0755);
    $keyLink=$workspace.'/linked.key';symlink($keyPath,$keyLink);
    foreach ([
        [$cipherExport,$wrongKey,$offlineScratch],
        [$trailing,$keyPath,$offlineScratch],
        [$truncated,$keyPath,$offlineScratch],
        [$cipherExport,$keyPath,$publicScratch],
        [$cipherExport,$keyLink,$offlineScratch],
    ] as $invalid) {
        $denied=false;
        try { drill_exec(array_merge([PHP_BINARY,$offlineScript],$invalid)); }
        catch (RuntimeException $expectedError) { $denied=true; }
        drill_assert($denied,'offline_recovery_invalid_input_rejected');
        drill_assert(count(scandir($offlineScratch))===2,'offline_failure_scratch_cleaned');
    }
    unlink($cipherExport);
    // Stale files must never be exported as a misleading recoverable copy.
    touch($candidates[0],time()-40*3600);
    $staleDenied=false;
    try {
        drill_exec([PHP_BINARY,$script,'export-latest']);
    } catch (RuntimeException $expectedError) {
        $staleDenied=true;
    } finally {
        touch($candidates[0]);
    }
    drill_assert($staleDenied,'stale_ciphertext_export_rejected');
    $recoveryKey = file_get_contents($workspace.'/drawmeone-backup-recovery.key');
    if (!is_string($recoveryKey) ||
        strlen($recoveryKey)!==SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
        throw new RuntimeException('Temporary recovery key invalid');
    }
    $restored=$workspace.'/restored';
    mkdir($restored,0700);
    $plain=$restored.'/snapshot.tar.gz';
    drill_decrypt($candidates[0],$plain,$recoveryKey);
    sodium_memzero($recoveryKey);
    $tar = '/usr/bin/tar';
    drill_exec([$tar,'-xzf',$plain,'-C',$restored,'--no-same-owner','--no-same-permissions']);
    $sql=$restored.'/database.sql';
    drill_assert(is_file($sql) && filesize($sql)>256,
        'independent_reader_recovers_sql_dump');
    $dump=file_get_contents($sql);
    drill_assert(is_string($dump)&&!str_contains($dump,$invoiceUrl)&&!str_contains($dump,$appKey),'sql_dump_excludes_raw_invoice_and_app_key');unset($dump);
    $expected=[
        $restored.'/drawmeone-artworks/'.$key.'/thumb.webp',
        $restored.'/drawmeone-artworks/'.$key.'/display.webp',
        $restored.'/drawmeone-references/'.$refKey.'/thumb.webp',
        $restored.'/drawmeone-references/'.$refKey.'/display.webp',
        $restored.'/drawmeone-deliveries/'.$deliveryKey.'/display.webp',
    ];
    foreach ($expected as $image) {
        if (!is_file($image) || !is_readable($image) ||
            substr((string)file_get_contents($image,false,null,0,4),0,4)!=='RIFF') {
            throw new RuntimeException('Recovered image is absent or malformed');
        }
    }
    drill_assert(count($expected)===5,'artwork_reference_and_delivery_variants_restored');
    // Imported separately from the source CI schema. The restore schema is
    // granted to ci_user only by the disposable Actions service setup.
    $client = $restored.'/mariadb.cnf';
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    file_put_contents($client,
        "[client]\nhost=\"127.0.0.1\"\nuser=\"".$user."\"\npassword=\"".$pass."\"\n");
    chmod($client,0600);
    drill_exec(['/usr/bin/mariadb',
        '--defaults-extra-file='.$client,'drawmeone_restore_ci'],$sql);
    $restore = new PDO(
        'mysql:host=127.0.0.1;dbname=drawmeone_restore_ci;charset=utf8mb4',
        $user, $pass,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
    );
    drill_assert((int)$restore->query(
        'SELECT COUNT(*) FROM dmo_portfolio_artworks')->fetchColumn()===1 &&
        (int)$restore->query('SELECT COUNT(*) FROM dmo_artwork_files')->fetchColumn()===2,
        'restored_database_contains_artwork_and_variants');
    drill_assert((int)$restore->query(
        'SELECT COUNT(*) FROM dmo_request_attachments')->fetchColumn()===1 &&
        (int)$restore->query('SELECT COUNT(*) FROM dmo_users')->fetchColumn()===2,
        'restored_database_contains_customer_reference');
    $files=$restore->query('SELECT storage_key,byte_size,pixel_width,pixel_height FROM dmo_artwork_files')->fetchAll(PDO::FETCH_ASSOC);
    foreach($files as $record){
        $file=$restored.'/drawmeone-artworks/'.$record['storage_key'];
        $info=is_file($file)?getimagesize($file):false;
        drill_assert(is_array($info)&&($info['mime']??null)==='image/webp'&&filesize($file)===(int)$record['byte_size']&&$info[0]===(int)$record['pixel_width']&&$info[1]===(int)$record['pixel_height'],'restored_portfolio_variant_metadata_matches');
    }
    $references=$restore->query('SELECT storage_key,byte_size,pixel_width,pixel_height FROM dmo_request_attachments')->fetchAll(PDO::FETCH_ASSOC);
    foreach($references as $record){
        $file=$restored.'/drawmeone-references/'.$record['storage_key'];
        $info=is_file($file)?getimagesize($file):false;
        drill_assert(is_array($info)&&($info['mime']??null)==='image/webp'&&filesize($file)===(int)$record['byte_size']&&$info[0]===(int)$record['pixel_width']&&$info[1]===(int)$record['pixel_height'],'restored_reference_metadata_matches');
        drill_assert(is_file(dirname($file).'/thumb.webp'),'restored_reference_thumbnail_present');
    }
    $draft=$restore->query('SELECT storage_key,sha256,byte_size FROM dmo_delivery_drafts')->fetch(PDO::FETCH_ASSOC);
    $draftPath=$restored.'/drawmeone-deliveries/'.$draft['storage_key'];
    drill_assert(is_file($draftPath)&&filesize($draftPath)===(int)$draft['byte_size']&&hash_equals($draft['sha256'],hash_file('sha256',$draftPath)),'restored_delivery_record_matches_checksum_and_file');
    drill_assert($restore->query("SELECT version FROM dmo_schema_migrations WHERE version='005_artwork_reviews'")->fetchColumn()==='005_artwork_reviews','restored_schema_includes_artwork_reviews');
    drill_assert((int)$restore->query('SELECT COUNT(*) FROM dmo_artwork_reviews')->fetchColumn()===0,'restored_review_table_available');
    drill_assert($restore->query("SELECT version FROM dmo_schema_migrations WHERE version='006_checkout_links'")->fetchColumn()==='006_checkout_links','restored_checkout_schema_available');
    $invoice=$restore->query('SELECT order_id,url_ciphertext,url_fingerprint,provider_host FROM dmo_checkout_links')->fetch(PDO::FETCH_ASSOC);
    drill_assert(is_array($invoice)&&$invoice['url_ciphertext']===$invoiceCipher&&dmo_checkout_authenticated_url($invoice)===$invoiceUrl,'restored_invoice_authenticates_with_original_app_key');
    $scan=dmo_integrity_checkout_crypto($restore);
    drill_assert($scan===['checked'=>1,'invalid'=>0,'complete'=>true],'restored_invoice_integrity_scan_passes');
    putenv('DMO_APP_KEY='.bin2hex(random_bytes(32)));
    drill_assert(dmo_checkout_authenticated_url($invoice)===null&&dmo_integrity_checkout_crypto($restore)['invalid']===1,'restored_invoice_rejects_wrong_app_key');
    putenv('DMO_APP_KEY='.$appKey);
    drill_assert(dmo_checkout_authenticated_url(array_replace($invoice,['order_id'=>$orderId+1]))===null,'restored_invoice_rejects_wrong_order_binding');
    drill_assert(dmo_checkout_authenticated_url($invoice)===$invoiceUrl,'restored_invoice_original_key_revalidated');
    sodium_memzero($appKey);unset($invoiceUrl,$invoiceCipher,$invoice);
    drill_assert(true,'restored_database_media_references_match_files');
    $sourceUsers=(int)$db->query('SELECT COUNT(*) FROM dmo_users')->fetchColumn();
    drill_assert($sourceUsers===2,'original_disposable_database_unchanged');
    echo "DISPOSABLE_FULL_BACKUP_RESTORE=PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR,"DISPOSABLE_FULL_BACKUP_RESTORE=FAIL (".
        get_class($error).": ".$error->getMessage().")\n");
    exit(1);
} finally {
    drill_cleanup($workspace);
}
