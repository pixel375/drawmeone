<?php
declare(strict_types=1);

/**
 * Draw Me One — private PHP application bootstrap.
 *
 * No credentials are stored in the repository. The .env file MUST reside
 * outside public_html. This directory is also blocked by app/.htaccess.
 */
date_default_timezone_set('UTC');

function dmo_private_env_path(): ?string
{
    $webroot = realpath(dirname(__DIR__));
    if ($webroot === false) {
        return null;
    }

    $override = getenv('DMO_ENV_FILE');
    $candidates = [];
    if (is_string($override) && $override !== '') {
        $candidates[] = $override;
    }

    // Hostinger usual layout: /home/uXXXXX/domains/drawmeone.com/public_html
    // Personal private folder: /home/uXXXXX/private/.env
    $candidates[] = dirname($webroot, 3) . '/private/.env';
    // Alternative domain-specific private folder:
    $candidates[] = dirname($webroot) . '/private/.env';
    // Alternative single public_html inside account home:
    $candidates[] = dirname($webroot) . '/.env';

    foreach (array_unique($candidates) as $candidate) {
        $path = realpath($candidate);
        if ($path === false || !is_file($path) || !is_readable($path)) {
            continue;
        }
        // Fail closed: never load configuration from a web-accessible path.
        $withinWebroot = $path === $webroot ||
            strncmp($path, $webroot . DIRECTORY_SEPARATOR, strlen($webroot) + 1) === 0;
        if (!$withinWebroot) {
            return $path;
        }
    }
    return null;
}

/** Read simple KEY=VALUE lines; comments, empty lines and quoted values work. */
function dmo_load_env(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [];
    $path = dmo_private_env_path();
    if ($path === null) {
        return $config;
    }

    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return $config;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (!preg_match('/^([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/D', $line, $match)) {
            continue;
        }
        $value = trim($match[2]);
        $length = strlen($value);
        if ($length >= 2 &&
            (($value[0] === '"' && $value[$length - 1] === '"') ||
             ($value[0] === "'" && $value[$length - 1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        $config[$match[1]] = $value;
    }
    return $config;
}

function dmo_config(string $name, string $default = ''): string
{
    $config = dmo_load_env();
    if (array_key_exists($name, $config)) {
        return (string) $config[$name];
    }
    // Also allow values injected by the hosting runtime.
    $value = getenv($name);
    return is_string($value) ? $value : $default;
}

function dmo_database(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = dmo_config('DB_HOST');
    $name = dmo_config('DB_NAME');
    $user = dmo_config('DB_USER');
    $password = dmo_config('DB_PASS');

    if ($host === '' || $name === '' || $user === '' || $password === '') {
        throw new RuntimeException('Backend database configuration is incomplete.');
    }

    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('The PDO MySQL PHP extension is unavailable.');
    }

    $pdo = new PDO(
        'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function dmo_api_headers(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('Access-Control-Allow-Origin: https://drawmeone.com');
    header('Vary: Origin');
}

function dmo_json(int $status, array $payload): never
{
    http_response_code($status);
    dmo_api_headers();
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function dmo_reject_non_get(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        header('Allow: GET');
        dmo_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }
}

function dmo_log_problem(Throwable $exception, string $context): void
{
    // Never send SQL credentials, error text, or SQL statements to visitors.
    error_log('[DrawMeOne] ' . $context . ': ' . get_class($exception));
}
