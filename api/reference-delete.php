<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/references.php';

// Owner-only reference removal. The DB visibility record is deleted first;
// the private files are then unlinked. No raw storage path leaves the server.
try {
    $db = dmo_database();
    $customer = dmo_customer_require($db);
    $input = dmo_auth_json_request();
    dmo_auth_verify_csrf($customer);
    if (!dmo_refs_enabled($db)) {
        dmo_json(503, ['ok' => false, 'error' => 'reference_management_disabled']);
    }
    if (count($input) !== 2 ||
        array_diff(array_keys($input), ['ref','attachment_id']) !== []) {
        dmo_json(422, ['ok' => false, 'error' => 'unexpected_fields']);
    }
    $ref = $input['ref'] ?? null;
    $attachmentId = $input['attachment_id'] ?? null;
    if (!is_string($ref) || !preg_match('/^DMO-[a-f0-9]{20}$/D', $ref) ||
        !is_int($attachmentId) || $attachmentId < 1) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_attachment']);
    }
    $db->beginTransaction();
    try {
        $requestQuery = $db->prepare(
            'SELECT id, status FROM dmo_commission_requests ' .
            'WHERE public_ref = :ref AND customer_id = :customer LIMIT 1 FOR UPDATE'
        );
        $requestQuery->execute([
            'ref' => $ref, 'customer' => (int) $customer['user_id'],
        ]);
        $request = $requestQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($request)) {
            $db->rollBack();
            dmo_json(404, ['ok' => false, 'error' => 'reference_not_found']);
        }
        if (!dmo_messages_request_open((string) $request['status'])) {
            $db->rollBack();
            dmo_json(409, ['ok' => false, 'error' => 'request_closed']);
        }
        $record = $db->prepare(
            'SELECT id, storage_key FROM dmo_request_attachments WHERE ' .
            'id = :id AND request_id = :request AND uploaded_by = :customer LIMIT 1'
        );
        $record->execute([
            'id' => $attachmentId,
            'request' => (int) $request['id'],
            'customer' => (int) $customer['user_id'],
        ]);
        $attachment = $record->fetch(PDO::FETCH_ASSOC);
        if (!is_array($attachment)) {
            $db->rollBack();
            dmo_json(404, ['ok' => false, 'error' => 'reference_not_found']);
        }
        // Validate the key against the private storage root before changing DB.
        $thumb = dmo_refs_storage_file((string) $attachment['storage_key'], 'thumb');
        $display = dmo_refs_storage_file((string) $attachment['storage_key'], 'display');
        $db->prepare('DELETE FROM dmo_request_attachments WHERE id = :id')
            ->execute(['id' => $attachmentId]);
        $db->prepare(
            "INSERT INTO dmo_audit_log (actor_id, action, entity_type, entity_id) " .
            "VALUES (:actor, 'request.reference_deleted', 'commission_request', :request)"
        )->execute([
            'actor' => (int) $customer['user_id'],
            'request' => (int) $request['id'],
        ]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
    // No endpoint can serve the files once the attachment row is deleted.
    $thumbDeleted = !is_file($thumb) || @unlink($thumb);
    $displayDeleted = !is_file($display) || @unlink($display);
    if ($thumbDeleted && $displayDeleted) {
        @rmdir(dirname($display));
    } else {
        error_log('[DrawMeOne] Reference deletion: private file cleanup incomplete.');
    }
    dmo_json(200, [
        'ok' => true,
        'reference_removed' => true,
        'storage_cleanup_complete' => $thumbDeleted && $displayDeleted,
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'private reference removal');
    dmo_json(503, ['ok' => false, 'error' => 'reference_service_unavailable']);
}
