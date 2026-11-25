<?php
declare(strict_types=1);

require_once __DIR__ . '/admin.php';

const DMO_ARTWORK_MAX_BYTES = 8 * 1024 * 1024;
const DMO_ARTWORK_MAX_PIXELS = 16000000;

function dmo_artwork_writes_enabled(): bool
{
    return dmo_config('DMO_ARTWORK_WRITES_ENABLED') === '1';
}

function dmo_artwork_id(mixed $value): int
{
    if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value)) {
        $value = (int) $value;
    }
    if (!is_int($value) || $value < 1) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_artwork_id']);
    }
    return $value;
}

function dmo_artwork_storage_root(): string
{
    $env = dmo_private_env_path();
    if ($env === null) {
        throw new RuntimeException('Private configuration path is unavailable.');
    }
    $root = dirname($env) . '/drawmeone-artworks';
    $webroot = realpath(dirname(__DIR__));
    // Defence in depth: never write upload data beneath public_html.
    $parent = realpath(dirname($root));
    if ($webroot === false || $parent === false ||
        $parent === $webroot ||
        str_starts_with($parent . '/', $webroot . '/')) {
        throw new RuntimeException('Unsafe private artwork root.');
    }
    return $root;
}

function dmo_artwork_storage_file(string $key): string
{
    if (!preg_match('/^[a-f0-9]{32}\/(thumb|display)\.webp$/D', $key)) {
        throw new InvalidArgumentException('Unsafe artwork storage key.');
    }
    return dmo_artwork_storage_root() . '/' . $key;
}

function dmo_artwork_open_image(string $tmp): array
{
    if (!is_uploaded_file($tmp) || !extension_loaded('gd') ||
        !function_exists('imagewebp')) {
        dmo_json(503, ['ok' => false, 'error' => 'image_processing_unavailable']);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    if (!is_string($mime) ||
        !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        dmo_json(422, ['ok' => false, 'error' => 'unsupported_image_format']);
    }
    $size = @getimagesize($tmp);
    if (!is_array($size) || ($size['mime'] ?? '') !== $mime) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_image_data']);
    }
    $width = (int) ($size[0] ?? 0);
    $height = (int) ($size[1] ?? 0);
    if ($width < 100 || $height < 100 ||
        $width > 6500 || $height > 6500 ||
        $width * $height > DMO_ARTWORK_MAX_PIXELS) {
        dmo_json(422, ['ok' => false, 'error' => 'image_dimensions_out_of_range']);
    }
    $source = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($tmp),
        'image/png' => @imagecreatefrompng($tmp),
        'image/webp' => @imagecreatefromwebp($tmp),
    };
    if (!$source instanceof GdImage) {
        dmo_json(422, ['ok' => false, 'error' => 'image_decode_failed']);
    }
    return [$source, $width, $height];
}

function dmo_artwork_make_webp(
    GdImage $source, int $width, int $height, string $path, int $longest, int $quality
): array {
    $scale = min(1.0, $longest / max($width, $height));
    $w = max(1, (int) round($width * $scale));
    $h = max(1, (int) round($height * $scale));
    $canvas = imagecreatetruecolor($w, $h);
    if (!$canvas instanceof GdImage) {
        throw new RuntimeException('Unable to allocate image canvas.');
    }
    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);
    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, $w - 1, $h - 1, $transparent);
    $copied = imagecopyresampled(
        $canvas, $source, 0, 0, 0, 0, $w, $h, $width, $height
    );
    $written = $copied && imagewebp($canvas, $path, $quality);
    imagedestroy($canvas);
    if (!$written || !is_file($path) || !chmod($path,0600) || filesize($path) < 100) {
        throw new RuntimeException('Unable to write optimized image.');
    }
    return ['width' => $w, 'height' => $h, 'bytes' => filesize($path)];
}

function dmo_artwork_record_audit(PDO $db, int $adminId, int $artworkId, string $action): void
{
    $stmt = $db->prepare(
        "INSERT INTO dmo_audit_log (actor_id, action, entity_type, entity_id) " .
        "VALUES (:admin, :action, 'portfolio_artwork', :artwork)"
    );
    $stmt->execute([
        'admin' => $adminId, 'action' => $action, 'artwork' => $artworkId,
    ]);
}

function dmo_artwork_send_file(PDO $db, int $id, string $variant, bool $admin): never
{
    // Errors and private previews must not be retained by caches.
    header('Cache-Control: private, no-store');
    if (!in_array($variant, ['thumb', 'display'], true)) {
        http_response_code(404);
        exit;
    }
    $stmt = $db->prepare(
        'SELECT f.storage_key, f.byte_size FROM dmo_artwork_files f ' .
        'JOIN dmo_portfolio_artworks a ON a.id = f.artwork_id ' .
        'WHERE a.id = :id AND f.variant = :variant ' .
        ($admin ? '' : "AND a.visibility = 'published' ") .
        'LIMIT 1'
    );
    $stmt->execute(['id' => $id, 'variant' => $variant]);
    $found = $stmt->fetch();
    if (!is_array($found)) {
        http_response_code(404);
        exit;
    }
    $path = dmo_artwork_storage_file((string) $found['storage_key']);
    if (!is_file($path) || !is_readable($path)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: image/webp');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Content-Security-Policy: default-src ' . "'none'");
    if (!$admin) {
        // Revalidate every public image request against current visibility.
        // Only emit a 304 after the published row and readable file are verified.
        $digest = hash_file('sha256', $path);
        header('Cache-Control: public, no-cache, must-revalidate');
        if (is_string($digest)) {
            $etag = '"' . $digest . '"';
            header('ETag: ' . $etag);
            $requestTags = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
            if (is_string($requestTags)) {
                foreach (explode(',', $requestTags) as $tag) {
                    $tag = trim($tag);
                    if (str_starts_with($tag, 'W/')) $tag = substr($tag, 2);
                    if ($tag === '*' || $tag === $etag) {
                        http_response_code(304);
                        exit;
                    }
                }
            }
        }
    }
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}
