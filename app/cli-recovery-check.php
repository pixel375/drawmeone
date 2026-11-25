<?php
declare(strict_types=1);
// Run on an isolated, trusted recovery workstation, never through HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (count($argv) !== 4) {
    fwrite(STDERR, "Usage: php app/cli-recovery-check.php <encrypted-snapshot> <separate-key-file> <private-scratch-directory>\n");
    exit(2);
}
umask(0077);
require_once __DIR__.'/backup-archive.php';
$key = null;
try {
    [$script, $snapshot, $keyPath, $scratch] = $argv;
    if (!extension_loaded('sodium')) throw new RuntimeException('Crypto unavailable.');
    foreach ([$snapshot, $keyPath] as $file) {
        if (is_link($file) || !is_file($file) || !is_readable($file) ||
            (fileperms($file) & 0077) !== 0) {
            throw new RuntimeException('Input must be a private regular file.');
        }
    }
    if (is_link($scratch) || !is_dir($scratch) || !is_writable($scratch) ||
        (fileperms($scratch) & 0077) !== 0) {
        throw new RuntimeException('Scratch must be a private directory.');
    }
    $resolvedScratch = realpath($scratch);
    if ($resolvedScratch === false) throw new RuntimeException('Invalid scratch directory.');
    $key = file_get_contents($keyPath, false, null, 0,
        SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES + 1);
    if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
        throw new RuntimeException('Invalid key.');
    }
    // The archive check authenticates every frame and final tag before
    // inspecting the permitted SQL/media archive in disposable scratch.
    $inspection = dmo_backup_restore_check($snapshot, $key, $resolvedScratch);
    echo "offline_archive_check=passed\n";
    echo "private_image_files_checked=".$inspection['private_image_files']."\n";
    echo "private_directories_checked=".$inspection['private_directories']."\n";
    echo "sql_import_attempted=no\n";
} catch (Throwable $error) {
    // Never disclose filenames, secret bytes or customer data.
    fwrite(STDERR, "offline_archive_check=failed\n");
    exit(1);
} finally {
    if (is_string($key)) sodium_memzero($key);
}
