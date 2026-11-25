<?php
declare(strict_types=1);
// Archive primitives shared by the host backup and disconnected recovery CLI.
// No application bootstrap, configuration, database or network access.

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


/**
 * Recover into an isolated private scratch directory only. This checks the
 * archive member paths, decrypted SQL dump and private image files without
 * importing ANY SQL or touching production image paths.
 */
function dmo_backup_decrypt(string $backup, string $destination, string $key): void
{
    $input = fopen($backup, 'rb');
    $output = @fopen($destination, 'xb');
    if (!is_resource($input) || !is_resource($output)) {
        if (is_resource($input)) fclose($input);
        throw new RuntimeException('Unable to open recovery streams.');
    }
    chmod($destination, 0600);
    try {
        if (dmo_backup_read_exact($input, 6) !== 'DMOBK2') {
            throw new RuntimeException('Unknown private recovery format.');
        }
        $header = dmo_backup_read_exact(
            $input, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES
        );
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        if (!is_string($state)) throw new RuntimeException('Invalid recovery header.');
        $finished = false;
        while (!$finished) {
            $frame = unpack('Nlength', dmo_backup_read_exact($input, 4));
            $length = (int) ($frame['length'] ?? 0);
            if ($length < SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES ||
                $length > 1024 * 1024 + 256) {
                throw new RuntimeException('Invalid encrypted recovery frame.');
            }
            $decoded = sodium_crypto_secretstream_xchacha20poly1305_pull(
                $state, dmo_backup_read_exact($input, $length)
            );
            if (!is_array($decoded)) throw new RuntimeException('Recovery authentication failed.');
            dmo_backup_write_all($output, $decoded[0]);
            $finished = $decoded[1] ===
                SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
        }
        if (fread($input, 1) !== '') throw new RuntimeException('Unexpected encrypted data.');
        fflush($output);
    } finally {
        fclose($input);
        fclose($output);
    }
}

function dmo_backup_remove_scratch(string $root): void
{
    if (!is_dir($root) || is_link($root)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($root);
}

function dmo_backup_restore_check(string $source, string $key, string $root): array
{
    $free = disk_free_space($root);
    if ($free === false || $free < 200 * 1024 * 1024) {
        throw new RuntimeException('Insufficient free space for isolated recovery.');
    }
    $scratch = $root . '/.recovery-check-' . bin2hex(random_bytes(8));
    if (!mkdir($scratch, 0700)) {
        throw new RuntimeException('Private recovery workspace cannot be created.');
    }
    try {
        $archive = $scratch . '/snapshot.tar.gz';
        dmo_backup_decrypt($source, $archive, $key);
        $tar = dmo_backup_binary(['tar']);
        $list = $scratch . '/members.txt';
        dmo_backup_command([$tar, '-tzf', $archive], $list);
        $contents = file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($contents) || !in_array('database.sql', $contents, true)) {
            throw new RuntimeException('Recovered archive lacks the database.');
        }
        if (count($contents) !== count(array_unique($contents))) {
            throw new RuntimeException('Duplicate recovery archive member.');
        }
        foreach ($contents as $member) {
            // Only allow the exact relative paths our private archiver writes.
            // Prevent traversal, symlinks and arbitrary files during extraction.
            if ($member === 'database.sql') continue;
            if (!preg_match(
                '~^drawmeone-(?:artworks|references|deliveries)/(?:[a-f0-9]{32}/(?:thumb|display)\.webp)?$~D',
                $member
            ) && !preg_match(
                '~^drawmeone-(?:artworks|references|deliveries)/[a-f0-9]{32}/?$~D',
                $member
            )) {
                throw new RuntimeException('Unsafe recovery member name.');
            }
        }
        // GNU tar's verbose inventory starts each entry with its type.
        // Refuse symlinks, hard links and device nodes *before* extraction.
        $verbose = $scratch . '/members-verbose.txt';
        dmo_backup_command([$tar, '-tvzf', $archive], $verbose);
        $typeRows = file($verbose, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($typeRows) || count($typeRows) !== count($contents)) {
            throw new RuntimeException('Recovered archive inventory inconsistent.');
        }
        foreach ($typeRows as $index => $typeRow) {
            $member = $contents[$index];
            $expectedType = ($member === 'database.sql' || str_ends_with($member, '.webp')) ? '-' : 'd';
            if (!is_string($typeRow) || $typeRow === '' || $typeRow[0] !== $expectedType) {
                throw new RuntimeException('Unexpected archive entry type.');
            }
        }
        $extracted = $scratch . '/extracted';
        if (!mkdir($extracted, 0700)) {
            throw new RuntimeException('Recovery extract directory unavailable.');
        }
        dmo_backup_command([
            $tar, '-xzf', $archive, '-C', $extracted,
            '--no-same-owner', '--no-same-permissions',
        ], '/dev/null');
        $sql = $extracted . '/database.sql';
        if (!is_file($sql) || is_link($sql) || filesize($sql) < 256) {
            throw new RuntimeException('Recovered SQL file absent or empty.');
        }
        $fp = fopen($sql, 'rb');
        if (!is_resource($fp)) throw new RuntimeException('Recovered SQL not readable.');
        $begin = fread($fp, 100000);
        fclose($fp);
        if (!is_string($begin) || !str_contains($begin, 'CREATE TABLE')) {
            throw new RuntimeException('Recovered SQL is missing schema.');
        }
        $images = 0;
        $dirs = 0;
        foreach (['drawmeone-artworks', 'drawmeone-references', 'drawmeone-deliveries'] as $base) {
            $folder = $extracted . '/' . $base;
            if (!file_exists($folder)) continue;
            if (is_link($folder) || !is_dir($folder)) {
                throw new RuntimeException('Invalid recovered private root.');
            }
            $scan = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($scan as $file) {
                if ($file->isLink() || !$file->isFile()) {
                    throw new RuntimeException('Unexpected recovered filesystem object.');
                }
                $data = fopen($file->getPathname(), 'rb');
                if (!is_resource($data)) throw new RuntimeException('Recovered image unreadable.');
                $header = fread($data, 12);
                fclose($data);
                if (!is_string($header) || strlen($header) !== 12 ||
                    substr($header, 0, 4) !== 'RIFF' ||
                    substr($header, 8, 4) !== 'WEBP') {
                    throw new RuntimeException('Invalid recovered WebP image.');
                }
                $images++;
            }
            $dirs++;
        }
        return ['private_image_files' => $images, 'private_directories' => $dirs];
    } finally {
        dmo_backup_remove_scratch($scratch);
    }
}

