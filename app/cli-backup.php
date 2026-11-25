<?php
declare(strict_types=1);
require_once __DIR__ . '/references.php';
require_once __DIR__ . '/deliveries.php';
require_once __DIR__ . '/backup-archive.php';

// Private encrypted, local-only operational backups. No HTTP entry point,
// no arbitrary paths/commands/SQL and no secrets in output or GitHub.
if (PHP_SAPI !== 'cli' || count($argv) !== 2 ||
    !in_array($argv[1], ['run', 'verify-latest', 'restore-check', 'export-latest'], true)) {
    fwrite(STDERR, "Usage: php app/cli-backup.php run|verify-latest|restore-check|export-latest\n");
    exit(2);
}
umask(0077);

function dmo_backup_root(): string
{
    $env = dmo_private_env_path();
    if ($env === null) {
        throw new RuntimeException('Private configuration missing.');
    }
    $dir = dirname($env) . '/drawmeone-encrypted-backups';
    if (is_link($dir)) throw new RuntimeException('Unsafe backup directory.');
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create backup directory.');
    }
    chmod($dir, 0700);
    return $dir;
}

function dmo_backup_key(bool $allowCreate): string
{
    if (!extension_loaded('sodium')) {
        throw new RuntimeException('Authenticated encryption unavailable.');
    }
    $env = dmo_private_env_path();
    if ($env === null) throw new RuntimeException('Private configuration unavailable.');
    // The recovery key is deliberately NOT inside the backup folder,
    // webroot or GitHub. It must eventually be copied offsite separately.
    $path = dirname($env) . '/drawmeone-backup-recovery.key';
    if (is_link($path)) throw new RuntimeException('Backup key is a symlink.');
    if (!file_exists($path) && $allowCreate) {
        $key = sodium_crypto_secretstream_xchacha20poly1305_keygen();
        $out = @fopen($path, 'xb');
        if (!is_resource($out)) throw new RuntimeException('Backup key initialization failed.');
        chmod($path, 0600);
        try {
            if (fwrite($out, $key) !== strlen($key)) {
                throw new RuntimeException('Backup key write failed.');
            }
            fflush($out);
        } finally {
            fclose($out);
        }
        sodium_memzero($key);
    }
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Backup recovery key unavailable.');
    }
    chmod($path, 0600);
    $key = file_get_contents($path);
    if (!is_string($key) ||
        strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
        throw new RuntimeException('Invalid recovery key length.');
    }
    return $key;
}

function dmo_backup_option(string $value): string
{
    if (preg_match('/[\x00-\x1f\x7f]/', $value)) {
        throw new RuntimeException('Invalid database option.');
    }
    return '"' . str_replace(['\\','"'], ['\\\\','\\"'], $value) . '"';
}

function dmo_backup_dump(string $work): void
{
    $host = dmo_config('DB_HOST');
    $name = dmo_config('DB_NAME');
    $user = dmo_config('DB_USER');
    $password = dmo_config('DB_PASS');
    if (!preg_match('/^[a-zA-Z0-9.-]+$/D', $host) ||
        !preg_match('/^[a-zA-Z0-9_]+$/D', $name) ||
        !preg_match('/^[a-zA-Z0-9_]+$/D', $user) ||
        $password === '') {
        throw new RuntimeException('Database backup configuration invalid.');
    }
    $defaults = $work . '/mysql-client.cnf';
    $text = "[client]\n" .
        "host=" . dmo_backup_option($host) . "\n" .
        "user=" . dmo_backup_option($user) . "\n" .
        "password=" . dmo_backup_option($password) . "\n";
    if (file_put_contents($defaults, $text, LOCK_EX) !== strlen($text)) {
        throw new RuntimeException('Temporary client configuration failed.');
    }
    chmod($defaults, 0600);
    $binary = dmo_backup_binary(['mariadb-dump','mysqldump']);
    try {
        dmo_backup_command([
            $binary, '--defaults-extra-file=' . $defaults,
            '--single-transaction', '--quick', '--hex-blob',
            '--default-character-set=utf8mb4', '--skip-lock-tables',
            '--skip-comments', '--no-tablespaces', $name,
        ], $work . '/database.sql');
    } finally {
        @unlink($defaults);
    }
    $dump = $work . '/database.sql';
    if (!is_file($dump) || filesize($dump) < 256) {
        throw new RuntimeException('SQL dump empty or invalid.');
    }
    // A backup without table definitions cannot be restored.
    $fp = fopen($dump, 'rb');
    if (!is_resource($fp)) throw new RuntimeException('SQL dump unreadable.');
    $prefix = fread($fp, 100000);
    fclose($fp);
    if (!is_string($prefix) || !str_contains($prefix, 'CREATE TABLE')) {
        throw new RuntimeException('SQL dump missing table definitions.');
    }
    chmod($dump, 0600);
}

function dmo_backup_archive(string $work): string
{
    $tar = dmo_backup_binary(['tar']);
    $archive = $work . '/site-data.tar.gz';
    $argv = [$tar,'-czf',$archive,'-C',$work,'database.sql'];
    $artParent = dirname(dmo_artwork_storage_root());
    $root = dmo_artwork_storage_root();
    $refs = dmo_refs_storage_root();
    foreach ([$root, $refs, dmo_delivery_storage_root()] as $dir) {
        if (is_link($dir)) throw new RuntimeException('Private image root is a symlink.');
        if (is_dir($dir)) {
            $argv[] = '-C';
            $argv[] = $artParent;
            $argv[] = basename($dir);
        }
    }
    dmo_backup_command($argv, '/dev/null');
    if (!is_file($archive) || filesize($archive) < 150) {
        throw new RuntimeException('Private archive missing.');
    }
    chmod($archive, 0600);
    // Integrity check of compressed archive before encryption.
    dmo_backup_command([$tar,'-tzf',$archive], '/dev/null');
    return $archive;
}

function dmo_backup_list(string $root): array
{
    $paths = glob($root . '/dmo-*.bak') ?: [];
    $paths = array_values(array_filter($paths, static fn(string $path) =>
        is_file($path) && !is_link($path) &&
        preg_match('/\/dmo-[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}\.bak$/D', $path) === 1
    ));
    rsort($paths, SORT_STRING);
    return $paths;
}

try {
    $root = dmo_backup_root();
    $lock = fopen($root . '/.backup.lock', 'c+');
    if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another backup operation is already running.');
    }
    try {
        $key = dmo_backup_key($argv[1] === 'run');
        if ($argv[1] === 'export-latest') {
            // Dedicated ciphertext-only SSH/SFTP transport. Never emit SQL,
            // private media in the clear, backup keys, or path metadata.
            $snapshots = dmo_backup_list($root);
            if (!$snapshots) throw new RuntimeException('No encrypted recovery snapshot.');
            $newest = $snapshots[0];
            $age = time() - (int) filemtime($newest);
            if ($age < -300 || $age > 36 * 3600) {
                throw new RuntimeException('Latest encrypted snapshot is not recent.');
            }
            if (!dmo_backup_verify($newest, $key)) {
                throw new RuntimeException('Encrypted export verification failed.');
            }
            $input = @fopen($newest, 'rb');
            $output = @fopen('php://stdout', 'wb');
            if (!is_resource($input) || !is_resource($output)) {
                if (is_resource($input)) fclose($input);
                throw new RuntimeException('Encrypted export stream unavailable.');
            }
            try {
                $expected = @filesize($newest);
                $written = stream_copy_to_stream($input, $output);
                if (!is_int($expected) || $expected < 256 ||
                    !is_int($written) || $expected !== $written) {
                    throw new RuntimeException('Encrypted export incomplete.');
                }
                fflush($output);
            } finally {
                fclose($input);
                fclose($output);
            }
        } elseif ($argv[1] === 'restore-check') {
            $snapshots = dmo_backup_list($root);
            if (!$snapshots) throw new RuntimeException('No recovery snapshot available.');
            $inspection = dmo_backup_restore_check($snapshots[0], $key, $root);
            echo "isolated_archive_restore_check=passed\n";
            echo "private_image_files_checked=" . $inspection['private_image_files'] . "\n";
            echo "production_database_unchanged=yes\n";
            echo "sql_import_attempted=no\n";
        } elseif ($argv[1] === 'verify-latest') {
            $snapshots = dmo_backup_list($root);
            if (!$snapshots) throw new RuntimeException('No backups yet.');
            if (!dmo_backup_verify($snapshots[0], $key)) {
                throw new RuntimeException('Latest backup verification failed.');
            }
            echo "encrypted_backup_latest_verified=yes\n";
            echo "encrypted_backup_count=" . count($snapshots) . "\n";
        } else {
            if (disk_free_space($root) < 200 * 1024 * 1024) {
                throw new RuntimeException('Insufficient private backup space.');
            }
            $work = $root . '/.work-' . bin2hex(random_bytes(8));
            if (!mkdir($work, 0700)) {
                throw new RuntimeException('Private backup staging failed.');
            }
            $temporary = '';
            try {
                dmo_backup_dump($work);
                $archive = dmo_backup_archive($work);
                $name = 'dmo-' . gmdate('Ymd\THis\Z') . '-' .
                    bin2hex(random_bytes(4)) . '.bak';
                $temporary = $root . '/.' . $name . '.tmp';
                dmo_backup_encrypt($archive, $temporary, $key);
                if (!dmo_backup_verify($temporary, $key)) {
                    throw new RuntimeException('Backup encryption verification failed.');
                }
                // Validate the exact newly encrypted snapshot by decrypting
                // and inspecting it in isolated private scratch storage.
                // Do this BEFORE promoting the snapshot or pruning older,
                // known-good backups. Failed recovery must never rotate them.
                dmo_backup_restore_check($temporary, $key, $root);
                $destination = $root . '/' . $name;
                if (!rename($temporary, $destination)) {
                    throw new RuntimeException('Backup promotion failed.');
                }
                $temporary = '';
                chmod($destination, 0600);
                // Keep the seven newest encrypted local snapshots.
                $all = dmo_backup_list($root);
                foreach (array_slice($all, 7) as $old) @unlink($old);
                echo "new_archive_restore_checked=yes\n";
                echo "encrypted_backup_created=yes\n";
                echo "encrypted_backup_verified=yes\n";
                echo "encrypted_backup_count=" . count(dmo_backup_list($root)) . "\n";
                echo "offsite_backup=not_configured\n";
            } finally {
                if ($temporary !== '') @unlink($temporary);
                @unlink($work . '/mysql-client.cnf');
                @unlink($work . '/database.sql');
                @unlink($work . '/site-data.tar.gz');
                @rmdir($work);
            }
        }
        sodium_memzero($key);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
} catch (Throwable $error) {
    // No SQL, file contents, database credentials, key, paths or exception text.
    fwrite(STDERR, "encrypted_backup_check=failed_" . get_class($error) . "\n");
    exit(1);
}
