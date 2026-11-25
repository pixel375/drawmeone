<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/artwork.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    dmo_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

try {
    $db = dmo_database();
    $admin = dmo_admin_require_session($db);
    // Multipart requests require the same verified origin and CSRF header as
    // JSON POSTs, but cannot use the JSON-only parser.
    if (!dmo_auth_https() ||
        ($_SERVER['HTTP_ORIGIN'] ?? '') !== DMO_AUTH_ORIGIN ||
        !str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data')) {
        dmo_json(403, ['ok' => false, 'error' => 'secure_upload_required']);
    }
    dmo_auth_verify_csrf($admin);
    if (!dmo_artwork_writes_enabled()) {
        dmo_json(503, ['ok' => false, 'error' => 'artwork_upload_disabled']);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > DMO_ARTWORK_MAX_BYTES + 1048576) {
        dmo_json(413, ['ok' => false, 'error' => 'upload_too_large']);
    }
    $file = $_FILES['artwork'] ?? null;
    if (!is_array($file) || is_array($file['tmp_name'] ?? null) ||
        (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
        !is_int($file['size'] ?? null) || $file['size'] <= 0 ||
        $file['size'] > DMO_ARTWORK_MAX_BYTES) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_upload']);
    }
    // Serialize the count and insert across separate administrator sessions.
    // This connection is nonpersistent and releases the lock on request exit.
    $lock=$db->prepare('SELECT GET_LOCK(:name,3)');
    $lock->execute(['name'=>dmo_artwork_upload_lock_name($db)]);
    if((int)$lock->fetchColumn()!==1)dmo_json(409,['ok'=>false,'error'=>'artwork_upload_busy']);
    $count = (int) $db->query('SELECT COUNT(*) FROM dmo_portfolio_artworks')->fetchColumn();
    if ($count >= 2000) {
        dmo_json(422, ['ok' => false, 'error' => 'artwork_library_limit']);
    }

    $tmp = (string) $file['tmp_name'];
    [$source, $width, $height] = dmo_artwork_open_image($tmp);
    // Never reuse the filename supplied by the browser and never store raw
    // input. The WebP re-encode strips EXIF and untrusted embedded content.
    $key = bin2hex(random_bytes(16));
    $slug = 'drawing-' . $key;
    $root = dmo_artwork_storage_root();
    if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
        throw new RuntimeException('Unable to initialize private artwork storage.');
    }
    if (is_link($root)) {
        throw new RuntimeException('Artwork storage cannot be a symlink.');
    }
    $folder = $root . '/' . $key;
    if (!mkdir($folder, 0700)) {
        throw new RuntimeException('Unable to create artwork folder.');
    }
    $saved = [];
    try {
        $variants = [
            'thumb' => ['max' => 840, 'quality' => 78],
            'display' => ['max' => 1900, 'quality' => 86],
        ];
        foreach ($variants as $variant => $spec) {
            $path = $folder . '/' . $variant . '.webp';
            $saved[$variant] = dmo_artwork_make_webp(
                $source, $width, $height, $path, $spec['max'], $spec['quality']
            );
        }
        imagedestroy($source);
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                "INSERT INTO dmo_portfolio_artworks (slug, visibility) " .
                "VALUES (:slug, 'draft')"
            );
            $stmt->execute(['slug' => $slug]);
            $artworkId = (int) $db->lastInsertId();
            $add = $db->prepare(
                'INSERT INTO dmo_artwork_files ' .
                '(artwork_id, variant, storage_key, mime_type, ' .
                'pixel_width, pixel_height, byte_size) ' .
                "VALUES (:id, :variant, :key, 'image/webp', :w, :h, :bytes)"
            );
            foreach ($saved as $variant => $meta) {
                $add->execute([
                    'id' => $artworkId,
                    'variant' => $variant,
                    'key' => $key . '/' . $variant . '.webp',
                    'w' => $meta['width'],
                    'h' => $meta['height'],
                    'bytes' => $meta['bytes'],
                ]);
            }
            dmo_artwork_record_audit(
                $db, (int) $admin['user_id'], $artworkId, 'artwork.uploaded_as_draft'
            );
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        dmo_json(201, [
            'ok' => true,
            'artwork_id' => $artworkId,
            'visibility' => 'draft',
            'width' => $width, 'height' => $height,
        ]);
    } catch (Throwable $error) {
        if ($source instanceof GdImage) imagedestroy($source);
        foreach (['thumb','display'] as $variant) {
            @unlink($folder . '/' . $variant . '.webp');
        }
        @rmdir($folder);
        throw $error;
    }
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin artwork upload');
    dmo_json(503, ['ok' => false, 'error' => 'upload_service_unavailable']);
}
