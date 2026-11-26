<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/commission-workflow.php';
require_once dirname(__DIR__, 2) . '/app/quotes.php';

// Artist explicitly releases an immutable draft to the customer's private
// account. This is NOT email delivery, order creation, checkout or payment.
try {
    $db = dmo_database();
    $admin = dmo_admin_require_session($db);
    $input = dmo_auth_json_request();
    dmo_auth_verify_csrf($admin);
    if (!dmo_quote_delivery_enabled()) {
        dmo_json(503, ['ok' => false, 'error' => 'quote_delivery_disabled']);
    }
    if (count($input) !== 3 ||
        array_diff(array_keys($input), ['request_id','quote_id','validity_days']) !== []) {
        dmo_json(422, ['ok' => false, 'error' => 'unexpected_fields']);
    }
    $requestId = dmo_workflow_id($input['request_id'] ?? null);
    $quoteId = dmo_workflow_id($input['quote_id'] ?? null);
    $days = $input['validity_days'] ?? null;
    if (!is_int($days) || $days < 1 || $days > 30) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_validity']);
    }
    if (!dmo_auth_rate_limit($db, 'admin-quote-release',
        (string) $admin['user_id'], 12, 60)) {
        dmo_json(429, ['ok' => false, 'error' => 'too_many_attempts']);
    }
    $db->beginTransaction();
    try {
        $lock = $db->prepare(
            'SELECT id, status, customer_id, policy_version FROM dmo_commission_requests ' .
            'WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $lock->execute(['id' => $requestId]);
        $request = $lock->fetch(PDO::FETCH_ASSOC);
        if (!is_array($request)) {
            $db->rollBack();
            dmo_json(404, ['ok' => false, 'error' => 'request_not_found']);
        }
        if (!dmo_messages_request_open((string) $request['status']) ||
            !dmo_policy_request_eligible($db,$requestId,(string)$request['policy_version'])) {
            $db->rollBack();
            dmo_json(409, ['ok' => false, 'error' => 'request_not_eligible']);
        }
        $customer = $db->prepare(
            "SELECT id FROM dmo_users WHERE id = :id AND role = 'customer' " .
            "AND account_status = 'active' AND email_verified_at IS NOT NULL LIMIT 1"
        );
        $customer->execute(['id' => $request['customer_id']]);
        if ($customer->fetchColumn() === false) {
            $db->rollBack();
            dmo_json(409, ['ok' => false, 'error' => 'customer_not_verified']);
        }
        $accepted = $db->prepare(
            "SELECT id FROM dmo_quotes WHERE request_id = :id " .
            "AND status = 'accepted' LIMIT 1"
        );
        $accepted->execute(['id' => $requestId]);
        if ($accepted->fetchColumn() !== false) {
            $db->rollBack();
            dmo_json(409, ['ok' => false, 'error' => 'quote_already_accepted']);
        }
        $maxVersion = $db->prepare(
            'SELECT MAX(version_number) FROM dmo_quotes WHERE request_id = :id'
        );
        $maxVersion->execute(['id' => $requestId]);
        $latest = (int) $maxVersion->fetchColumn();

        $quote = $db->prepare(
            'SELECT id, version_number, status, total_minor FROM dmo_quotes ' .
            'WHERE id = :id AND request_id = :request LIMIT 1 FOR UPDATE'
        );
        $quote->execute(['id' => $quoteId, 'request' => $requestId]);
        $row = $quote->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['status'] !== 'draft' ||
            (int) $row['version_number'] !== $latest ||
            (int) $row['total_minor'] < 1) {
            $db->rollBack();
            dmo_json(409, ['ok' => false, 'error' => 'quote_not_latest_draft']);
        }
        if(!dmo_quote_total_matches($db,$quoteId,(int)$row['total_minor'])){
            $db->rollBack();
            dmo_json(409,['ok'=>false,'error'=>'quote_total_inconsistent']);
        }
        // A new release supersedes previously sent (unaccepted) versions.
        $db->prepare(
            "UPDATE dmo_quotes SET status = 'superseded' " .
            "WHERE request_id = :id AND status = 'sent'"
        )->execute(['id' => $requestId]);
        $db->prepare(
            "UPDATE dmo_quotes SET status = 'sent', sent_at = UTC_TIMESTAMP(), " .
            "validity_ends_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL " . $days .
            " DAY) WHERE id = :id AND request_id = :request AND status = 'draft'"
        )->execute(['id' => $quoteId, 'request' => $requestId]);
        dmo_workflow_audit(
            $db, (int) $admin['user_id'], $requestId, 'quote.published_to_customer',
            ['quote_id' => $quoteId, 'version' => $latest,
             'validity_days' => $days, 'policy_version' => $request['policy_version'],'policy_sha256'=>dmo_policy_receipt($db,$requestId)]
        );
        $db->commit();
        dmo_json(200, [
            'ok' => true, 'quote_id' => $quoteId, 'version' => $latest,
            'status' => 'sent', 'delivery' => 'private_customer_account',
            'email_sent' => false, 'order_created' => false, 'payment_collected' => false,
        ]);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
} catch (Throwable $error) {
    dmo_log_problem($error, 'quote publication');
    dmo_json(503, ['ok' => false, 'error' => 'quote_release_unavailable']);
}
