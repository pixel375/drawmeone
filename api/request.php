<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/references.php';
dmo_reject_non_get();

try {
    $db = dmo_database();
    $session = dmo_customer_require($db);
    $ref = $_GET['ref'] ?? null;
    if (!is_string($ref) || !preg_match('/^DMO-[0-9a-f]{20}$/D', $ref)) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_request_reference']);
    }
    $stmt = $db->prepare(
        'SELECT r.id, r.public_ref, r.subject_type, r.brief, ' .
        'r.preferred_contact, r.preferred_deadline, r.status, ' .
        'r.submitted_at, r.updated_at, t.name AS tier_name ' .
        'FROM dmo_commission_requests r ' .
        'LEFT JOIN dmo_commission_tiers t ON t.id = r.tier_id ' .
        'WHERE r.public_ref = :ref AND r.customer_id = :customer LIMIT 1'
    );
    $stmt->execute(['ref' => $ref, 'customer' => $session['user_id']]);
    $request = $stmt->fetch();
    // Even if a reference exists for someone else, return an identical 404.
    if (!is_array($request)) {
        dmo_json(404, ['ok' => false, 'error' => 'request_not_found']);
    }
    $addons = $db->prepare(
        'SELECT a.name FROM dmo_request_addons ra ' .
        'JOIN dmo_addons a ON a.id = ra.addon_id ' .
        'WHERE ra.request_id = :id ORDER BY a.sort_order, a.id LIMIT 8'
    );
    $addons->execute(['id' => $request['id']]);
    $names = array_column($addons->fetchAll(), 'name');

    // Internal artist notes are NEVER returned. Draft quotes are also private
    // until a future explicit quote-delivery workflow is implemented.
    $messages = $db->prepare(
        'SELECT m.body, m.created_at, u.display_name AS sender_name ' .
        'FROM dmo_conversations c ' .
        'JOIN dmo_messages m ON m.conversation_id = c.id ' .
        'JOIN dmo_users u ON u.id = m.sender_id ' .
        'WHERE c.request_id = :id AND m.is_internal = 0 ' .
        'ORDER BY m.id DESC LIMIT 40'
    );
    $messages->execute(['id' => $request['id']]);
    $references = dmo_refs_metadata($db, (int) $request['id']);
    $canUpload = dmo_refs_enabled($db) &&
        dmo_messages_request_open((string) $request['status']) &&
        count($references) < DMO_REFERENCE_MAX_ITEMS;
    unset($request['id']);
    dmo_json(200, [
        'ok' => true,
        'request' => $request,
        'addons' => $names,
        'references' => $references,
        'can_upload_references' => $canUpload,
        'messages' => array_reverse($messages->fetchAll()),
        'can_reply' => dmo_messages_enabled() &&
            dmo_messages_request_open((string) $request['status']),
        'quotes_visible' => false,
        'payments_enabled' => false,
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'customer request detail');
    dmo_json(503, ['ok' => false, 'error' => 'request_service_unavailable']);
}
