<?php
declare(strict_types=1);
// DANGEROUS IN GENERAL: clears records. Guarded to the explicitly disposable
// localhost MariaDB CI database; never allow running against real Hostinger.
if (PHP_SAPI !== 'cli' ||
    getenv('DMO_CI_INTEGRATION') !== '1' ||
    getenv('DB_HOST') !== '127.0.0.1' ||
    getenv('DB_NAME') !== 'drawmeone_ci' ||
    getenv('DB_USER') !== 'ci_user') {
    fwrite(STDERR, "Refusing any non-disposable database.\n");
    exit(2);
}
require_once dirname(__DIR__) . '/app/bootstrap.php';
$db = dmo_database();
$tables = $db->query(
    "SELECT TABLE_NAME FROM information_schema.TABLES " .
    "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'dmo\\_%' " .
    "AND TABLE_NAME != 'dmo_schema_migrations' ORDER BY TABLE_NAME"
)->fetchAll(PDO::FETCH_COLUMN);
if (!is_array($tables) || count($tables) < 20) {
    fwrite(STDERR, "Unexpected CI schema; refusing reset.\n");
    exit(1);
}
$db->exec('SET FOREIGN_KEY_CHECKS=0');
try {
    foreach ($tables as $table) {
        if (!is_string($table) || !preg_match('/^dmo_[a-z_]+$/D', $table)) {
            throw new RuntimeException('Invalid disposable table name.');
        }
        $db->exec('TRUNCATE TABLE `' . $table . '`');
    }
} catch (Throwable $error) {
    fwrite(STDERR, "Disposable reset failed.\n");
    exit(1);
} finally {
    $db->exec('SET FOREIGN_KEY_CHECKS=1');
}
$counts = $db->query('SELECT COUNT(*) FROM dmo_users')->fetchColumn();
if ((int) $counts !== 0) {
    fwrite(STDERR, "Disposable fixtures remain.\n");
    exit(1);
}
echo "DISPOSABLE_RESET=PASS\n";
