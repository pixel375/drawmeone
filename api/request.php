<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/references.php';
require_once dirname(__DIR__) . '/app/quotes.php';
require_once dirname(__DIR__) . '/app/orders.php';
require_once dirname(__DIR__) . '/app/deliveries.php';
require_once dirname(__DIR__) . '/app/checkout.php';
require_once dirname(__DIR__) . '/app/cancellations.php';
dmo_reject_non_get();

try {
    $db = dmo_database();
    $session = dmo_customer_require($db);
    $ref = $_GET['ref'] ?? null;
    if (!is_string($ref) || !preg_match('/^DMO-[0-9a-f]{20}$/D', $ref)) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_request_reference']);
    }
    // Keep details, history and action eligibility in one consistent read
    // snapshot. Mutations still independently revalidate authoritative state.
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->beginTransaction();
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
        $db->rollBack();
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
        $db->rollBack();
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
        'ORDER BY m.id DESC LIMIT ' . ($full === 'full' ? (string)DMO_CONVERSATION_MAX_MESSAGES : '41')
    );
    $messages->execute(['id' => $request['id']]);
    $thread = $messages->fetchAll();
    $hasOlder = $full !== 'full' && count($thread)>40;
    if ($hasOlder) array_pop($thread);
    $references = dmo_refs_metadata($db, (int) $request['id']);
    $canManageReferences = dmo_refs_management_enabled($db);
    $referenceAvailability = !dmo_refs_enabled($db) ? 'disabled' :
        (!dmo_messages_request_open((string)$request['status']) ? 'closed' :
        (count($references)>=DMO_REFERENCE_MAX_ITEMS ? 'capacity' : 'available'));
    $canUpload = $referenceAvailability==='available';
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
    // The detail UI must not invite a proof decision that the protected
    // image/decision endpoints would already reject. The endpoints still
    // independently recheck ownership, state, payment, policy and integrity.
    foreach ($reviews as &$review) {
        unset($review['can_offer']);
        $review['can_respond'] = false;
        if ($review['latest'] === null) continue;
        unset($review['latest']['draft_id']);
        if ($review['latest']['status'] !== 'pending' ||
            !dmo_policy_request_eligible($db,(int)$request['id'],(string)$request['policy_version'])) continue;
        $eligible = $db->prepare('SELECT v.id'.dmo_review_owner_query().' AND v.id=:id LIMIT 1');
        $eligible->execute(['customer'=>(int)$session['user_id'],'id'=>$review['latest']['id']]);
        $review['can_respond'] = $eligible->fetchColumn() !== false;
    }
    unset($review);
    $request['policy_sha256']=dmo_policy_receipt($db,(int)$request['id']);
    $checkout=dmo_checkout_rows($db,(int)$request['id']);
    $canWithdraw=dmo_withdrawal_enabled()&&dmo_withdrawal_eligible($db,(int)$request['id'],$request['status']);
    $conversationFull=dmo_conversation_message_count($db,(int)$request['id'])>=DMO_CONVERSATION_MAX_MESSAGES;
    unset($request['id']);
    $result = [
        'ok' => true,
        'request' => $request,
        'addons' => $names,
        'references' => $references,
        'can_upload_references' => $canUpload,
        'reference_capacity'=>['used'=>count($references),'limit'=>DMO_REFERENCE_MAX_ITEMS,'availability'=>$referenceAvailability],
        'can_manage_references' => $canManageReferences,
        'messages' => array_reverse($thread),
        'messages_have_older' => $hasOlder,
        'conversation_full' => $conversationFull,
        'can_reply' => dmo_messages_enabled() &&
            dmo_messages_request_open((string) $request['status']),
        'quotes' => $quotes,
        'orders' => $orders,
        'deliveries' => $deliveries,
        'artwork_reviews' => $reviews,
        'quotes_visible' => count($quotes) > 0,
        'checkout_links'=>$checkout,
        'can_withdraw'=>$canWithdraw,
        'payments_enabled' => false,
    ];
    $db->commit();
    dmo_json(200, $result);
} catch (Throwable $error) {
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    dmo_log_problem($error, 'customer request detail');
    dmo_json(503, ['ok' => false, 'error' => 'request_service_unavailable']);
}
