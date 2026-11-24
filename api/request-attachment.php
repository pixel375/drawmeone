<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/references.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    dmo_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

try {
    $db = dmo_database();
    $customer = dmo_customer_require($db);
    if (!dmo_auth_https() ||
        ($_SERVER['HTTP_ORIGIN'] ?? '') !== DMO_AUTH_ORIGIN ||
        !str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')),
            'multipart/form-data')) {
        dmo_json(403, ['ok' => false, 'error' => 'secure_upload_required']);
    }
    dmo_auth_verify_csrf($customer);
    if (!dmo_refs_enabled($db)) {
        dmo_json(503, ['ok' => false, 'error' => 'reference_upload_disabled']);
    }
    $ref = $_POST['ref'] ?? null;
    if (!is_string($ref) || !preg_match('/^DMO-[a-f0-9]{20}$/D', $ref) ||
        count($_POST) !== 1 || count($_FILES) !== 1) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_request_reference']);
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) >
        DMO_REFERENCE_MAX_BYTES + 1048576) {
        dmo_json(413, ['ok' => false, 'error' => 'reference_too_large']);
    }
    $file = $_FILES['reference'] ?? null;
    if (!is_array($file) || is_array($file['tmp_name'] ?? null) ||
        (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
        !is_int($file['size'] ?? null) ||
        $file['size'] <= 0 || $file['size'] > DMO_REFERENCE_MAX_BYTES) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_reference_upload']);
    }
    // Check ownership before decoding potentially expensive image files.
    $owner = $db->prepare(
        'SELECT id, status FROM dmo_commission_requests ' .
        'WHERE public_ref = :ref AND customer_id = :customer LIMIT 1'
    );
    $owner->execute(['ref' => $ref, 'customer' => (int) $customer['user_id']]);
    $request = $owner->fetch(PDO::FETCH_ASSOC);
    if (!is_array($request)) {
        dmo_json(404, ['ok' => false, 'error' => 'request_not_found']);
    }
    if (!dmo_messages_request_open((string) $request['status'])) {
        dmo_json(409, ['ok' => false, 'error' => 'request_closed']);
    }

    [$source, $width, $height] = dmo_artwork_open_image((string) $file['tmp_name']);
    $folder = '';
    try {
        $root = dmo_refs_storage_root();
        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new RuntimeException('Private reference root unavailable.');
        }
        if (is_link($root)) {
            throw new RuntimeException('Private reference root may not be a symlink.');
        }
        $key = bin2hex(random_bytes(16));
        $folder = $root . '/' . $key;
        if (!mkdir($folder, 0700)) {
            throw new RuntimeException('Unable to create private reference folder.');
        }
        dmo_artwork_make_webp($source, $width, $height,
            $folder . '/thumb.webp', 480, 76);
        $display = dmo_artwork_make_webp($source, $width, $height,
            $folder . '/display.webp', 1600, 83);
        imagedestroy($source);

        $db->beginTransaction();
        try {
            $lock = $db->prepare(
                'SELECT id, status FROM dmo_commission_requests ' .
                'WHERE id = :id AND customer_id = :customer LIMIT 1 FOR UPDATE'
            );
            $lock->execute([
                'id' => $request['id'], 'customer' => (int) $customer['user_id'],
            ]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current) ||
                !dmo_messages_request_open((string) $current['status'])) {
                $db->rollBack();
                dmo_json(409, ['ok' => false, 'error' => 'request_closed']);
            }
            $count = $db->prepare(
                'SELECT COUNT(*) FROM dmo_request_attachments WHERE request_id = :id'
            );
            $count->execute(['id' => $request['id']]);
            if ((int) $count->fetchColumn() >= DMO_REFERENCE_MAX_ITEMS) {
                $db->rollBack();
                dmo_json(409, ['ok' => false, 'error' => 'reference_limit_reached']);
            }
            if (!dmo_auth_rate_limit(
                $db, 'reference-upload-ip', '', 16, 60
            ) || !dmo_auth_rate_limit(
                $db, 'reference-upload-user', (string) $customer['user_id'], 12, 60
            )) {
                $db->rollBack();
                dmo_json(429, ['ok' => false, 'error' => 'too_many_uploads']);
            }
            $insert = $db->prepare(
                'INSERT INTO dmo_request_attachments ' .
                '(request_id, uploaded_by, storage_key, mime_type, ' .
                'pixel_width, pixel_height, byte_size) VALUES ' .
                "(:request, :customer, :key, 'image/webp', :width, :height, :bytes)"
            );
            $insert->execute([
                'request' => (int) $request['id'],
                'customer' => (int) $customer['user_id'],
                'key' => $key . '/display.webp',
                'width' => $display['width'],
                'height' => $display['height'],
                'bytes' => $display['bytes'],
            ]);
            $id = (int) $db->lastInsertId();
            $db->prepare(
                "INSERT INTO dmo_audit_log (actor_id, action, entity_type, entity_id) " .
                "VALUES (:customer, 'request.reference_uploaded', 'commission_request', :request)"
            )->execute([
                'customer' => (int) $customer['user_id'],
                'request' => (int) $request['id'],
            ]);
            $db->commit();
            dmo_json(201, [
                'ok' => true, 'attachment_id' => $id,
                'width' => $display['width'], 'height' => $display['height'],
            ]);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    } catch (Throwable $error) {
        if ($source instanceof GdImage) imagedestroy($source);
        if ($folder !== '') {
            @unlink($folder . '/thumb.webp');
            @unlink($folder . '/display.webp');
            @rmdir($folder);
        }
        throw $error;
    }
} catch (Throwable $error) {
    dmo_log_problem($error, 'customer reference upload');
    dmo_json(503, ['ok' => false, 'error' => 'reference_service_unavailable']);
}
