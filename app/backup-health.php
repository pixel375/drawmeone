<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/**
 * Read-only, admin-only backup readiness metadata.
 *
 * Do not treat this as proof of decryptability, offsite storage, or successful
 * recovery. The CLI and disposable restore tests provide those other checks.
 * Never return filenames, filesystem paths, key bytes or contents.
 *
 * @return array{ready:bool,state:string,count:int}
 */
function dmo_backup_local_health(): array
{
    $unavailable = static fn(string $reason): array => [
        'ready' => false, 'state' => $reason, 'count' => 0,
    ];
    $env = dmo_private_env_path();
    if ($env === null) return $unavailable('private_configuration_missing');

    $base = dirname($env);
    $root = $base . '/drawmeone-encrypted-backups';
    $key = $base . '/drawmeone-backup-recovery.key';
    $webroot = realpath(dirname(__DIR__));
    $realBase = realpath($base);
    if ($webroot === false || $realBase === false ||
        $realBase === $webroot ||
        str_starts_with($realBase . '/', $webroot . '/')) {
        return $unavailable('unsafe_location');
    }
    clearstatcache(true);
    if (!extension_loaded('sodium') ||
        !is_dir($root) || is_link($root) ||
        !is_file($key) || is_link($key)) {
        return $unavailable('storage_or_key_missing');
    }
    $rootMode = @fileperms($root);
    $keyMode = @fileperms($key);
    $keyBytes = @filesize($key);
    if (!is_int($rootMode) || !is_int($keyMode) ||
        ($rootMode & 0077) !== 0 || ($keyMode & 0077) !== 0 ||
        $keyBytes !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
        return $unavailable('unsafe_permissions_or_key');
    }

    $files = [];
    foreach (glob($root . '/dmo-*.bak') ?: [] as $file) {
        if (!preg_match('~/dmo-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}\\.bak$~D', $file) ||
            is_link($file) || !is_file($file) || !is_readable($file)) {
            continue;
        }
        $mode = @fileperms($file);
        $size = @filesize($file);
        $mtime = @filemtime($file);
        if (!is_int($mode) || ($mode & 0077) !== 0 ||
            !is_int($size) || $size < 256 ||
            !is_int($mtime) || $mtime <= 0) {
            return $unavailable('unsafe_or_invalid_snapshot');
        }
        $files[] = $mtime;
    }
    if (!$files) return $unavailable('snapshot_missing');
    $newest = max($files);
    $age = time() - $newest;
    if ($age < -300 || $age > 36 * 3600) {
        return ['ready' => false, 'state' => 'snapshot_stale',
            'count' => count($files)];
    }
    return ['ready' => true, 'state' => 'recent_private_snapshot',
        'count' => count($files)];
}
