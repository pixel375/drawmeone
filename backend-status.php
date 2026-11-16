<?php
declare(strict_types=1);
require_once __DIR__ . '/app/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$status = 'setup_required';
try {
    if (dmo_private_env_path() === null) {
        $status = 'private_env_not_found';
    } else {
        $incomplete = false;
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $key) {
            if (dmo_config($key) === '') {
                $incomplete = true;
            }
        }
        if ($incomplete) {
            $status = 'database_config_incomplete';
        } elseif (!extension_loaded('pdo_mysql')) {
            $status = 'pdo_mysql_unavailable';
        } else {
            $db = dmo_database();
            try {
                $statement = $db->prepare(
                    "SELECT version FROM dmo_schema_migrations WHERE version='001_initial_schema' LIMIT 1"
                );
                $statement->execute();
                $status = $statement->fetchColumn() === '001_initial_schema'
                    ? 'database_connected'
                    : 'schema_setup_required';
            } catch (PDOException $e) {
                dmo_log_problem($e, 'schema check');
                $status = 'schema_setup_required';
            }
        }
    }
} catch (PDOException $e) {
    dmo_log_problem($e, 'database connection');
    $status = 'database_connection_failed';
} catch (Throwable $e) {
    dmo_log_problem($e, 'setup check');
    $status = 'setup_required';
}

$stages = [
    'database_connected' => ['Connected', 'Your private settings, MySQL connection and initial database schema are working. The backend is still read-only.'],
    'private_env_not_found' => ['Private file not found', 'The PHP runtime cannot find the private .env outside public_html. Check its actual path and PHP read permissions.'],
    'database_config_incomplete' => ['Settings incomplete', 'A required DB_* entry is empty or missing in the private .env. Do not share its value.'],
    'pdo_mysql_unavailable' => ['MySQL driver unavailable', 'Enable the pdo_mysql PHP extension in Hostinger PHP settings.'],
    'database_connection_failed' => ['Database connection failed', 'The private file is readable but PHP could not connect to MySQL. Privately check Hostinger host/name/user/password and database-user privileges.'],
    'schema_setup_required' => ['Schema not found', 'The database connection worked, but migration 001_initial_schema is missing. Check the selected database in phpMyAdmin.'],
    'setup_required' => ['Setup not ready', 'An unexpected PHP setup problem prevented the health check. Review the private server error log.'],
];
[$heading, $description] = $stages[$status];
$esc = static fn(string $text): string =>
    htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>PHP connection check | Draw Me One</title>
<style>
:root{color-scheme:dark;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#141418;color:#f4f1f0}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:22px;box-sizing:border-box}
main{width:min(100%,580px);border:1px solid #49414a;background:#211f27;border-radius:18px;padding:clamp(23px,5vw,40px)}
.logo{font-weight:900;letter-spacing:-.07em;font-size:26px}.logo span{color:#ecb9b6}
.kicker{color:#eabbb8;font-size:11px;letter-spacing:.14em;font-weight:800;margin:33px 0 9px}
h1{font-size:clamp(29px,6vw,43px);line-height:1.1;letter-spacing:-.05em;margin:8px 0 14px}
p{color:#cbc6ca;line-height:1.75}
.status{border:1px solid #625a65;padding:17px;border-radius:10px;background:#19181f;margin:25px 0}
.status strong{display:block;margin-bottom:10px;font-size:17px}
.status code{font-size:12px;overflow-wrap:anywhere;color:#f1b9b6}
.status p{font-size:13px;margin:9px 0 0}
a{color:#efc0b4}a:focus-visible{outline:3px solid #efc0b4;outline-offset:3px}
small{display:block;line-height:1.7;color:#b4aeb7;margin-top:20px}
</style>
</head>
<body>
<main>
<div class="logo">drawmeone<span>.</span></div>
<p class="kicker">PHP / MYSQL · BACKEND PHASE 1</p>
<h1>Database connection</h1>
<p>This page performs the health check directly in PHP. It displays no database credentials, server paths, or private data.</p>
<div class="status" role="status">
<strong><?= $esc($heading) ?></strong>
<code id="status-code"><?= $esc($status) ?></code>
<p><?= $esc($description) ?></p>
</div>
<p><a href="./backend-status.php?refresh=1">Check again</a></p>
<small>All customer registration, commission submissions, messaging, artwork uploads and payment writes are currently disabled. The status code above is safe to share; your .env contents are not.</small>
<p><a href="./index.html">← Return to website</a></p>
</main>
</body>
</html>
