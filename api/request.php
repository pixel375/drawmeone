<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/references.php';
require_once dirname(__DIR__) . '/app/quotes.php';
require_once dirname(__DIR__) . '/app/orders.php';
require_once dirname(__DIR__) . '/app/deliveries.php';
require_once dirname(__DIR__) . '/app/checkout.php';
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
        'r.submitted_at, r.updated_at, r.policy_version, r.tier_snapshot_json, t.name AS tier_name ' .
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
        'SELECT a.name, ra.addon_snapshot_json FROM dmo_request_addons ra ' .
        'JOIN dmo_addons a ON a.id = ra.addon_id ' .
        'WHERE ra.request_id = :id ORDER BY a.sort_order, a.id LIMIT 8'
    );
    $addons->execute(['id' => $request['id']]);
    $names = [];
    foreach ($addons->fetchAll() as $row) {
        $name = dmo_requests_snapshot_name($row['addon_snapshot_json'], $row['name']);
        if ($name !== null) $names[] = $name;
    }
    $request = dmo_requests_historical_tier($request);

    $full = $_GET['thread'] ?? 'recent';
    if (!is_string($full) || !in_array($full,['recent','full'],true)) {
        dmo_json(422,['ok'=>false,'error'=>'invalid_thread_view']);
    }
    // Internal artist notes are NEVER returned. Draft quotes are also private
    // until a future explicit quote-delivery workflow is implemented.
    $messages = $db->prepare(
        'SELECT m.body, m.created_at, u.display_name AS sender_name ' .
        'FROM dmo_conversations c ' .
        'JOIN dmo_messages m ON m.conversation_id = c.id ' .
        'JOIN dmo_users u ON u.id = m.sender_id ' .
        'WHERE c.request_id = :id AND m.is_internal = 0 ' .
        'ORDER BY m.id DESC LIMIT ' . ($full === 'full' ? '500' : '41')
    );
    $messages->execute(['id' => $request['id']]);
    $thread = $messages->fetchAll();
    $hasOlder = $full !== 'full' && count($thread)>40;
    if ($hasOlder) array_pop($thread);
    $references = dmo_refs_metadata($db, (int) $request['id']);
    $canManageReferences = dmo_refs_management_enabled($db);
    $canUpload = dmo_refs_enabled($db) &&
        dmo_messages_request_open((string) $request['status']) &&
        count($references) < DMO_REFERENCE_MAX_ITEMS;
    $quotes = dmo_quote_customer_rows(
        $db, (int) $request['id'], (string) $request['policy_version']
    );
    if (!dmo_messages_request_open((string) $request['status'])) {
        foreach ($quotes as &$quoted) $quoted['can_respond'] = false;
        unset($quoted);
    }
    $orders = dmo_orders_customer_rows($db, (int) $request['id']);
    $latest = $quotes[0] ?? [];
    foreach ($quotes as $candidate) if ($candidate['status']==='accepted') { $latest=$candidate; break; }
    $summary=dmo_requests_list_summary(['status'=>$request['status'], 'order_status'=>$orders[0]['status']??null,
        'payment_exception'=>match($orders[0]['payment_status']??null){'on_hold'=>'payment.access_held','refunded'=>'payment.full_refund_recorded',default=>null},
        'visible_quote_status'=>$latest['status']??null,'visible_quote_expired'=>$latest['is_expired']??false]);
    $request['workflow_stage']=$summary['workflow_stage'];
    $deliveries=dmo_delivery_customer_rows($db,(int)$session['user_id'],(int)$request['id']);
    $reviews=dmo_review_enabled($db)?dmo_review_orders($db,(int)$request['id']):[];
    foreach($reviews as &$review){unset($review['can_offer']);if($review['latest']!==null)unset($review['latest']['draft_id']);}unset($review);
    $request['policy_sha256']=dmo_policy_receipt($db,(int)$request['id']);
    $checkout=dmo_checkout_rows($db,(int)$request['id']);
    unset($request['id']);
    dmo_json(200, [
        'ok' => true,
        'request' => $request,
        'addons' => $names,
        'references' => $references,
        'can_upload_references' => $canUpload,
        'can_manage_references' => $canManageReferences,
        'messages' => array_reverse($thread),
        'messages_have_older' => $hasOlder,
        'can_reply' => dmo_messages_enabled() &&
            dmo_messages_request_open((string) $request['status']),
        'quotes' => $quotes,
        'orders' => $orders,
        'deliveries' => $deliveries,
        'artwork_reviews' => $reviews,
        'quotes_visible' => count($quotes) > 0,
        'checkout_links'=>$checkout,
        'payments_enabled' => false,
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'customer request detail');
    dmo_json(503, ['ok' => false, 'error' => 'request_service_unavailable']);
}
