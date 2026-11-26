<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/commission-workflow.php';
require_once dirname(__DIR__, 2) . '/app/orders.php';

// Separate artist confirmation after the customer accepts a quote.
// Creates an UNPAID/UNVERIFIED record only. Never creates a payment record.
try {
    $db = dmo_database();
    $admin = dmo_admin_require_session($db);
    $input = dmo_auth_json_request();
    dmo_auth_verify_csrf($admin);
    if (!dmo_orders_admin_enabled()) {
        dmo_json(503, ['ok' => false, 'error' => 'order_confirmation_disabled']);
    }
    if (count($input) !== 3 ||
        array_diff(array_keys($input), ['request_id', 'accepted_quote_id', 'confirm_unpaid']) !== []) {
        dmo_json(422, ['ok' => false, 'error' => 'unexpected_fields']);
    }
    $requestId = dmo_workflow_id($input['request_id'] ?? null);
    $quoteId = dmo_workflow_id($input['accepted_quote_id'] ?? null);
    if (($input['confirm_unpaid'] ?? null) !== true) {
        dmo_json(422, ['ok' => false, 'error' => 'explicit_unpaid_acknowledgement_required']);
    }
    if (!dmo_auth_rate_limit(
        $db, 'admin-order-confirm', (string) $admin['user_id'], 8, 60
    )) {
        dmo_json(429, ['ok' => false, 'error' => 'too_many_attempts']);
    }
    $db->beginTransaction();
    try {
        $requestQuery = $db->prepare(
            'SELECT id, status, customer_id, policy_version FROM dmo_commission_requests ' .
            'WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $requestQuery->execute(['id' => $requestId]);
        $request = $requestQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($request)) {
            $db->rollBack();
            dmo_json(404, ['ok' => false, 'error' => 'request_not_found']);
        }
        if (!dmo_messages_request_open((string) $request['status']) ||
            !dmo_policy_request_eligible($db,$requestId,(string)$request['policy_version'])) {
            $db->rollBack();
            dmo_json(409, ['ok' => false, 'error' => 'request_not_eligible']);
        }
        $existing = $db->prepare(
            'SELECT id FROM dmo_orders WHERE request_id = :request LIMIT 1'
        );
        $existing->execute(['request' => $requestId]);
        if ($existing->fetchColumn() !== false) {
            $db->rollBack();
            dmo_json(409, ['ok' => false, 'error' => 'order_already_exists']);
        }
        $customer = $db->prepare(
            "SELECT id FROM dmo_users WHERE id = :id AND role = 'customer' " .
            "AND account_status = 'active' AND email_verified_at IS NOT NULL LIMIT 1"
        );
        $customer->execute(['id' => (int) $request['customer_id']]);
        if ($customer->fetchColumn() === false) {
            $db->rollBack();
            dmo_json(409, ['ok' => false, 'error' => 'customer_not_verified']);
        }
        $quoteQuery = $db->prepare(
            'SELECT id, total_minor, currency, status, accepted_at FROM dmo_quotes ' .
            'WHERE id = :id AND request_id = :request LIMIT 1 FOR UPDATE'
        );
        $quoteQuery->execute(['id' => $quoteId, 'request' => $requestId]);
        $quote = $quoteQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($quote) || $quote['status'] !== 'accepted' ||
            $quote['accepted_at'] === null || (int) $quote['total_minor'] < 1 ||
            !in_array((string) $quote['currency'], ['CAD','USD'], true)) {
            $db->rollBack();
            dmo_json(409, ['ok' => false, 'error' => 'accepted_quote_required']);
        }
        if(!dmo_quote_total_matches($db,$quoteId,(int)$quote['total_minor'])){
            $db->rollBack();dmo_json(409,['ok'=>false,'error'=>'quote_total_inconsistent']);
        }
        $insert = $db->prepare(
            'INSERT INTO dmo_orders ' .
            '(request_id, accepted_quote_id, status, payment_status) ' .
            "VALUES (:request, :quote, 'awaiting_arrangement', 'unverified')"
        );
        $insert->execute(['request' => $requestId, 'quote' => $quoteId]);
        $orderId = (int) $db->lastInsertId();
        dmo_workflow_audit(
            $db, (int) $admin['user_id'], $requestId,
            'order.confirmed_unpaid',
            ['order_id' => $orderId, 'quote_id' => $quoteId, 'payment_verified' => false]
        );
        $db->commit();
        dmo_json(201, [
            'ok' => true, 'order_id' => $orderId,
            'status' => 'awaiting_arrangement',
            'payment_status' => 'unverified',
            'payment_collected' => false,
            'payment_provider_contacted' => false,
        ]);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin unpaid order confirmation');
    dmo_json(503, ['ok' => false, 'error' => 'order_confirmation_unavailable']);
}
