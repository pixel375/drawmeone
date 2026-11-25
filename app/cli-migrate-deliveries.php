<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

// Fixed additive schema migration; no arbitrary queries, backup exports,
// email, customer records or credentials are accepted on stdin/argv.
if (PHP_SAPI !== 'cli' || count($argv) !== 2 || $argv[1] !== 'apply') {
    fwrite(STDERR, "Usage: php app/cli-migrate-deliveries.php apply\n");
    exit(2);
}
try {
    if (dmo_config('DMO_AUTH_ENABLED') === '1' ||
        dmo_config('DMO_REGISTRATION_ENABLED') === '1' ||
        dmo_config('DMO_REQUESTS_ENABLED') === '1' ||
        dmo_config('DMO_REFERENCES_ENABLED') === '1' ||
        dmo_config('DMO_DELIVERY_DRAFTS_ENABLED') === '1') {
        throw new RuntimeException('Customer service must remain closed for migration.');
    }
    $db = dmo_database();
    $sql = file_get_contents(dirname(__DIR__) . '/database/004_delivery_drafts.sql');
    if (!is_string($sql) || strlen($sql) < 500 || strlen($sql) > 8000 ||
        substr_count($sql, 'CREATE TABLE IF NOT EXISTS dmo_delivery_drafts') !== 1) {
        throw new RuntimeException('Expected static schema migration not installed.');
    }
    // The source migration contains precisely two fixed statements.
    $parts = array_values(array_filter(array_map(
        'trim', preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: []
    )));
    if (count($parts) !== 2) {
        throw new RuntimeException('Unexpected migration statement count.');
    }
    $db->exec($parts[0]);
    // Mark migration ready only after verifying the actual table structure.

    $columns = [];
    foreach ($db->query('SHOW COLUMNS FROM dmo_delivery_drafts') as $row) {
        $columns[] = (string) $row['Field'];
    }
    foreach (['id','request_id','uploaded_by','storage_key','order_id','sha256',
              'pixel_width','pixel_height','byte_size','created_at'] as $needed) {
        if (!in_array($needed, $columns, true)) {
            throw new RuntimeException('Attachment table structure mismatch.');
        }
    }
    $db->exec($parts[1]);
    $q=$db->prepare('SELECT version FROM dmo_schema_migrations WHERE version=:version');
    $q->execute(['version'=>'004_delivery_drafts']);
    if($q->fetchColumn()!=='004_delivery_drafts')throw new RuntimeException('Migration version did not persist.');
    echo "delivery_draft_schema_ready=yes\n";
    echo "customer_service_unchanged=closed\n";
} catch (Throwable $error) {
    // Do not emit server exceptions or SQL contents to public Actions logs.
    fwrite(STDERR, "delivery_draft_migration_failed=" . get_class($error) . "\n");
    exit(1);
}
