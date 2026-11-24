<?php
declare(strict_types=1);

// Safe unit smoke for path-validation and closed conversation states.
// No customer records, network requests, database writes or file uploads.
if (PHP_SAPI !== 'cli') exit(2);
$tmp = tempnam(sys_get_temp_dir(), 'dmo-ref-test-');
if (!is_string($tmp)) exit(1);
file_put_contents($tmp, "# Temporary nonsecret test configuration\n");
putenv('DMO_ENV_FILE=' . $tmp);
try {
    require_once dirname(__DIR__) . '/app/references.php';
    $id = str_repeat('a', 32);
    $path = dmo_refs_storage_file($id . '/display.webp', 'thumb');
    $checks = [
        str_ends_with($path, '/drawmeone-references/' . $id . '/thumb.webp'),
        dmo_messages_request_open('submitted'),
        dmo_messages_request_open('in_progress'),
        !dmo_messages_request_open('cancelled'),
        !dmo_messages_request_open('completed'),
        !dmo_messages_request_open('archived'),
    ];
    $rejected = 0;
    foreach ([
        ['../../etc/passwd', 'thumb'],
        [$id . '/display.webp', '../../wrong'],
        [$id . '/thumb.webp', 'thumb'],
    ] as $bad) {
        try {
            dmo_refs_storage_file($bad[0], $bad[1]);
        } catch (InvalidArgumentException $error) {
            $rejected++;
        }
    }
    if (in_array(false, $checks, true) || $rejected !== 3) {
        throw new RuntimeException('Reference safety assertions failed.');
    }
    echo "PRIVATE_REFERENCE_SMOKE=PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, "PRIVATE_REFERENCE_SMOKE=FAIL\n");
    exit(1);
} finally {
    @unlink($tmp);
}
