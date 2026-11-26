<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/quotes.php';

// A customer's explicit response to a released quote. No payment provider,
// financial charge, automatic order or copyright transfer is performed here.
try {
    $db = dmo_database();
    $session = dmo_customer_require($db);
    $input = dmo_auth_json_request();
    dmo_auth_verify_csrf($session);
    if (!dmo_quote_response_enabled()) {
        dmo_json(503, ['ok' => false, 'error' => 'quote_responses_disabled']);
    }
    if (count($input) !== 5 ||
        array_diff(array_keys($input), [
            'ref','quote_id','decision','policy_version','acknowledge_quote',
        ]) !== []) {
        dmo_json(422, ['ok' => false, 'error' => 'unexpected_fields']);
    }
    $ref = $input['ref'] ?? null;
    $quoteId = $input['quote_id'] ?? null;
    $decision = $input['decision'] ?? null;
    $version = $input['policy_version'] ?? null;
    if (!is_string($ref) || !preg_match('/^DMO-[a-f0-9]{20}$/D', $ref) ||
        !is_int($quoteId) || $quoteId < 1 ||
        !in_array($decision, ['accept','decline'], true) ||
        !is_string($version) ||
        !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,39}$/D',$version) ||
        ($decision === 'accept' && ($input['acknowledge_quote'] ?? false) !== true) ||
        ($decision === 'decline' && ($input['acknowledge_quote'] ?? null) !== false)) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_quote_decision']);
    }
    if (!dmo_auth_rate_limit($db, 'quote-response-user',
        (string) $session['user_id'], 8, 60)) {
        dmo_json(429, ['ok' => false, 'error' => 'too_many_attempts']);
    }

    $db->beginTransaction();
    try {
        // Serialize with artist release and other simultaneous decisions.
        $requestLookup = $db->prepare(
            'SELECT id, status, policy_version FROM dmo_commission_requests ' .
            'WHERE public_ref = :ref AND customer_id = :customer LIMIT 1 FOR UPDATE'
        );
        $requestLookup->execute([
            'ref' => $ref, 'customer' => (int) $session['user_id'],
        ]);
        $request = $requestLookup->fetch(PDO::FETCH_ASSOC);
        if (!is_array($request)) {
            $db->rollBack();
            dmo_json(404, ['ok' => false, 'error' => 'quote_not_found']);
        }
        if (!dmo_messages_request_open((string) $request['status']) ||
            !hash_equals($version, (string) $request['policy_version']) ||
            !dmo_policy_request_eligible($db,(int)$request['id'],$version)) {
            $db->rollBack();
            dmo_json(409, ['ok' => false, 'error' => 'request_policy_or_status_changed']);
        }
        $quoteLookup = $db->prepare(
            'SELECT id, status, total_minor, sent_at, validity_ends_at, ' .
            'CASE WHEN validity_ends_at IS NOT NULL AND ' .
            'validity_ends_at <= UTC_TIMESTAMP() THEN 1 ELSE 0 END AS is_expired ' .
            'FROM dmo_quotes WHERE id = :id AND request_id = :request LIMIT 1 FOR UPDATE'
        );
        $quoteLookup->execute(['id' => $quoteId, 'request' => $request['id']]);
        $quote = $quoteLookup->fetch(PDO::FETCH_ASSOC);
        if (!is_array($quote)) {
            $db->rollBack();
            dmo_json(404, ['ok' => false, 'error' => 'quote_not_found']);
        }
        if (!dmo_quote_can_respond($quote, true, (string) $request['policy_version'],true)) {
            $db->rollBack();
            dmo_json(409, ['ok' => false, 'error' => 'quote_expired_or_not_open']);
        }
        if($decision==='accept'&&!dmo_quote_total_matches($db,$quoteId,(int)$quote['total_minor'])){
            $db->rollBack();dmo_json(409,['ok'=>false,'error'=>'quote_total_inconsistent']);
        }
        $accepted = $db->prepare(
            "SELECT id FROM dmo_quotes WHERE request_id = :request AND status = 'accepted' LIMIT 1"
        );
        $accepted->execute(['request' => $request['id']]);
        if ($accepted->fetchColumn() !== false) {
            $db->rollBack();
            dmo_json(409, ['ok' => false, 'error' => 'another_quote_accepted']);
        }
        $newStatus = $decision === 'accept' ? 'accepted' : 'declined';
        $change = $db->prepare(
            "UPDATE dmo_quotes SET status = :status, " .
            "accepted_at = CASE WHEN :accept_flag = 1 THEN UTC_TIMESTAMP() " .
            "ELSE NULL END WHERE id = :id AND request_id = :request AND status = 'sent'"
        );
        $change->execute([
            'status' => $newStatus,
            'accept_flag' => $decision === 'accept' ? 1 : 0,
            'id' => $quoteId, 'request' => $request['id'],
        ]);
        if ($change->rowCount() !== 1) {
            throw new RuntimeException('Concurrent quote state change.');
        }
        $audit = $db->prepare(
            "INSERT INTO dmo_audit_log (actor_id, action, entity_type, entity_id, details_json) " .
            "VALUES (:actor, :action, 'commission_request', :entity, :details)"
        );
        $audit->execute([
            'actor' => (int) $session['user_id'],
            'action' => $decision === 'accept' ?
                'quote.customer_accepted' : 'quote.customer_declined',
            'entity' => (int) $request['id'],
            'details' => json_encode([
                'quote_id' => $quoteId, 'policy_version' => $version,
                'policy_sha256'=>dmo_policy_receipt($db,(int)$request['id']),
                'acknowledged' => $decision === 'accept',
            ], JSON_THROW_ON_ERROR),
        ]);
        $db->commit();
        dmo_json(200, [
            'ok' => true, 'quote_id' => $quoteId, 'status' => $newStatus,
            'order_created' => false, 'payment_collected' => false,
            'message' => $decision === 'accept' ?
                'Your agreement to this quote was recorded. No payment has been taken.' :
                'Quote declined. You may contact the artist to discuss a revision.',
        ]);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
} catch (Throwable $error) {
    dmo_log_problem($error, 'customer quote response');
    dmo_json(503, ['ok' => false, 'error' => 'quote_response_unavailable']);
}
