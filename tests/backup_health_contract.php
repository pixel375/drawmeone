<?php
declare(strict_types=1);
// Read-only backup-health fixtures; never touch production paths or keys.
if (PHP_SAPI !== 'cli' || getenv('DMO_CI_INTEGRATION') !== '1') {
    fwrite(STDERR,"CI-only contract.\n");
    exit(2);
}
$root=sys_get_temp_dir().'/dmo-health-ci-'.bin2hex(random_bytes(8));
if(!mkdir($root,0700)) exit(2);
$env=$root.'/.env';
file_put_contents($env,"# Fake configuration\n");
chmod($env,0600);
putenv('DMO_ENV_FILE='.$env);
require_once dirname(__DIR__).'/app/backup-health.php';
function health_assert(bool $test,string $label): void {
    if(!$test) throw new RuntimeException('BACKUP_HEALTH_FAIL='.$label);
    echo "PASS: ".$label."\n";
}
function clean_health(string $dir):void {
    foreach(glob($dir.'/*')?:[] as $item) {
        if(is_link($item)||is_file($item)) @unlink($item);
        elseif(is_dir($item)) clean_health($item);
    }
    foreach(glob($dir.'/.env')?:[] as $item) @unlink($item);
    @rmdir($dir);
}
try {
    $result=dmo_backup_local_health();
    health_assert(!$result['ready'] && $result['state']==='storage_or_key_missing',
        'missing_storage_and_key_are_blocked');
    $backupRoot=$root.'/drawmeone-encrypted-backups';
    mkdir($backupRoot,0700);
    $keyPath=$root.'/drawmeone-backup-recovery.key';
    file_put_contents($keyPath,random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES));
    chmod($keyPath,0600);
    $result=dmo_backup_local_health();
    health_assert(!$result['ready'] && $result['state']==='snapshot_missing',
        'key_without_snapshot_is_blocked');
    $snapshot=$backupRoot.'/dmo-20261124T120000Z-abcdef12.bak';
    file_put_contents($snapshot,'DMOBK2'.random_bytes(384));
    chmod($snapshot,0600);
    $result=dmo_backup_local_health();
    health_assert($result['ready'] && $result['state']==='recent_private_snapshot' &&
        $result['count']===1,'private_recent_snapshot_metadata_is_ready');
    chmod($snapshot,0644);
    $result=dmo_backup_local_health();
    health_assert(!$result['ready'] && $result['state']==='unsafe_or_invalid_snapshot',
        'world_readable_snapshot_is_blocked');
    chmod($snapshot,0600);
    chmod($keyPath,0644);
    health_assert(!dmo_backup_local_health()['ready'],
        'world_readable_recovery_key_is_blocked');
    chmod($keyPath,0600);
    chmod($backupRoot,0755);
    health_assert(!dmo_backup_local_health()['ready'],
        'world_traversable_backup_directory_is_blocked');
    chmod($backupRoot,0700);
    touch($snapshot,time()-40*3600);
    $result=dmo_backup_local_health();
    health_assert(!$result['ready'] && $result['state']==='snapshot_stale',
        'stale_snapshot_is_blocked');
    touch($snapshot);
    file_put_contents($keyPath,'invalid-short-key');
    health_assert(!dmo_backup_local_health()['ready'],
        'invalid_recovery_key_length_is_blocked');
    file_put_contents($keyPath,random_bytes(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES));
    chmod($keyPath,0600);
    unlink($snapshot);
    $outside=$root.'/external-target';
    file_put_contents($outside,'DMOBK2'.random_bytes(384));
    symlink($outside,$snapshot);
    health_assert(!dmo_backup_local_health()['ready'],
        'symlink_snapshot_is_not_trusted');
    unlink($snapshot);
    unlink($outside);
    echo "BACKUP_HEALTH_CONTRACT=PASS\n";
} catch(Throwable $error) {
    fwrite(STDERR,"BACKUP_HEALTH_CONTRACT=FAIL (".
        get_class($error).": ".$error->getMessage().")\n");
    exit(1);
} finally {
    clean_health($root);
}
