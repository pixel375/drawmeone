<?php
declare(strict_types=1);
require_once __DIR__ . '/references.php';

// Private encrypted, local-only operational backups. No HTTP entry point,
// no arbitrary paths/commands/SQL and no secrets in output or GitHub.
if (PHP_SAPI !== 'cli' || count($argv) !== 2 ||
    !in_array($argv[1], ['run', 'verify-latest'], true)) {
    fwrite(STDERR, "Usage: php app/cli-backup.php run|verify-latest\n");
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

function dmo_backup_key(): string
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
    if (!file_exists($path)) {
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

function dmo_backup_binary(array $candidates): string
{
    $path = getenv('PATH') ?: '/usr/bin:/bin';
    foreach ($candidates as $name) {
        foreach (explode(':', $path) as $directory) {
            if ($directory === '' || $directory[0] !== '/') continue;
            $candidate = rtrim($directory, '/') . '/' . $name;
            if (is_file($candidate) && is_executable($candidate)) return $candidate;
        }
    }
    throw new RuntimeException('Required backup utility unavailable.');
}

function dmo_backup_command(array $command, string $output): void
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('Process execution unavailable.');
    }
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe','r'], 1 => ['file',$output,'w'], 2 => ['file','/dev/null','w']],
        $pipes, null, null, ['bypass_shell'=>true]
    );
    if (!is_resource($process)) throw new RuntimeException('Backup command failed to start.');
    if (isset($pipes[0])) fclose($pipes[0]);
    $result = proc_close($process);
    if ($result !== 0) throw new RuntimeException('Backup utility returned an error.');
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
    foreach ([$root, $refs] as $dir) {
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

function dmo_backup_write_all($handle, string $bytes): void
{
    $remaining = strlen($bytes);
    $start = 0;
    while ($start < $remaining) {
        $wrote = fwrite($handle, substr($bytes, $start));
        if (!is_int($wrote) || $wrote < 1) {
            throw new RuntimeException('Encrypted backup write failed.');
        }
        $start += $wrote;
    }
}

function dmo_backup_encrypt(string $source, string $destination, string $key): void
{
    $input = fopen($source, 'rb');
    $output = @fopen($destination, 'xb');
    if (!is_resource($input) || !is_resource($output)) {
        if (is_resource($input)) fclose($input);
        throw new RuntimeException('Cannot open private backup streams.');
    }
    chmod($destination, 0600);
    try {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        dmo_backup_write_all($output, "DMOBK2" . $header);
        $current = fread($input, 1024 * 1024);
        if (!is_string($current)) throw new RuntimeException('Cannot read backup input.');
        do {
            $next = fread($input, 1024 * 1024);
            if (!is_string($next)) throw new RuntimeException('Cannot read backup input.');
            $final = ($next === '' && feof($input));
            $tag = $final
                ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL
                : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            $encrypted = sodium_crypto_secretstream_xchacha20poly1305_push(
                $state, $current, '', $tag
            );
            dmo_backup_write_all($output, pack('N', strlen($encrypted)) . $encrypted);
            $current = $next;
        } while (!$final);
        fflush($output);
    } finally {
        fclose($input);
        fclose($output);
    }
}

function dmo_backup_read_exact($handle, int $bytes): string
{
    $buffer = '';
    while (strlen($buffer) < $bytes) {
        $chunk = fread($handle, $bytes - strlen($buffer));
        if (!is_string($chunk) || $chunk === '') {
            throw new RuntimeException('Truncated encrypted backup.');
        }
        $buffer .= $chunk;
    }
    return $buffer;
}

function dmo_backup_verify(string $path, string $key): bool
{
    $input = fopen($path, 'rb');
    if (!is_resource($input)) throw new RuntimeException('Encrypted backup unreadable.');
    try {
        if (dmo_backup_read_exact($input, 6) !== 'DMOBK2') {
            throw new RuntimeException('Encrypted backup format mismatch.');
        }
        $header = dmo_backup_read_exact(
            $input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
        );
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        if (!is_string($state)) throw new RuntimeException('Encrypted stream invalid.');
        $sawFinal = false;
        do {
            $len = unpack('Nlength', dmo_backup_read_exact($input, 4));
            $size = (int) ($len['length'] ?? 0);
            if ($size < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES ||
                $size > 1024 * 1024 + 256) {
                throw new RuntimeException('Encrypted chunk size invalid.');
            }
            $plain = sodium_crypto_secretstream_xchacha20poly1305_pull(
                $state, dmo_backup_read_exact($input, $size)
            );
            if (!is_array($plain)) {
                throw new RuntimeException('Encrypted backup authentication failed.');
            }
            $sawFinal = $plain[1] ===
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        } while (!$sawFinal);
        if (fread($input, 1) !== '') {
            throw new RuntimeException('Unexpected trailing encrypted data.');
        }
        return true;
    } finally {
        fclose($input);
    }
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
        $key = dmo_backup_key();
        if ($argv[1] === 'verify-latest') {
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
                $destination = $root . '/' . $name;
                if (!rename($temporary, $destination)) {
                    throw new RuntimeException('Backup promotion failed.');
                }
                $temporary = '';
                chmod($destination, 0600);
                // Keep the seven newest encrypted local snapshots.
                $all = dmo_backup_list($root);
                foreach (array_slice($all, 7) as $old) @unlink($old);
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
