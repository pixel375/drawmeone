<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/notifications.php';
require_once dirname(__DIR__, 2) . '/app/deliveries.php';
require_once dirname(__DIR__, 2) . '/app/payments.php';
require_once dirname(__DIR__, 2) . '/app/checkout.php';
require_once dirname(__DIR__, 2) . '/app/cancellations.php';

try {
    $db = dmo_database();
    $admin = dmo_admin_require_session($db);
    $input = dmo_auth_json_request();
    dmo_auth_verify_csrf($admin);
    if (!dmo_workflow_writes_enabled()) {
        dmo_json(503, ['ok' => false, 'error' => 'request_admin_writes_disabled']);
    }

    $action = $input['action'] ?? null;
    $requestId = dmo_workflow_id($input['request_id'] ?? null);
    if (in_array($action,['record_payment_evidence','verify_payment_evidence','reject_payment_evidence'],true)) dmo_payment_reconcile($db,$admin,$requestId,$input);
    if (in_array($action,['issue_checkout_link','revoke_checkout_link'],true)) dmo_checkout_apply($db,$admin,$requestId,$input);
    if ($action==='cancel_unpaid_order') dmo_cancel_order_apply($db,$admin,$requestId,$input);
    if ($action==='set_payment_exception') dmo_payment_exception_apply($db,$admin,$requestId,$input);
    if ($action==='offer_artwork_review') dmo_review_offer($db,$admin,$requestId,$input);
    if (in_array($action,['set_production_status','release_delivery'],true)) dmo_fulfillment_apply($db,$admin,$requestId,$input);
    if ($action === 'remove_delivery_draft') dmo_delivery_remove($db,$admin,$requestId,$input);
    if ($action === 'send_notification') dmo_notification_send($db,$admin,$requestId,$input);
    if (!in_array($action, ['add_private_note', 'create_quote_draft', 'send_customer_message','set_review_status','set_order_deadline','withdraw_quote'], true)) {
        dmo_json(422, ['ok' => false, 'error' => 'unsupported_action']);
    }

    // Validate EVERYTHING before opening a transaction so no error path
    // exits with an uncommitted transaction.
    $note = null;
    $messageKey=null;
    $quote = null;
    $quoteKey=null;
    $deadline = null;
    $orderId = null;
    $withdrawId = null;
    if ($action === 'withdraw_quote') {
        if (count($input)!==4 || array_diff(array_keys($input),
            ['action','request_id','quote_id','confirm_withdrawal'])!==[] ||
            ($input['confirm_withdrawal']??null)!==true) {
            dmo_json(422,['ok'=>false,'error'=>'explicit_quote_withdrawal_required']);
        }
        if (!dmo_quote_delivery_enabled()) dmo_json(503,['ok'=>false,'error'=>'quote_withdrawal_disabled']);
        $withdrawId=dmo_workflow_id($input['quote_id']??null);
    } elseif ($action === 'set_order_deadline') {
        if (count($input) !== 6 || array_diff(array_keys($input),
            ['action','request_id','order_id','expected_deadline','agreed_deadline','confirm_agreement']) !== [] ||
            ($input['confirm_agreement'] ?? null) !== true ||
            !array_key_exists('expected_deadline',$input) || !array_key_exists('agreed_deadline',$input) ||
            ($input['expected_deadline'] !== null && (!is_string($input['expected_deadline']) ||
                !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$input['expected_deadline'])))) {
            dmo_json(422,['ok'=>false,'error'=>'invalid_order_deadline_input']);
        }
        if (!dmo_orders_admin_enabled()) dmo_json(503,['ok'=>false,'error'=>'order_planning_disabled']);
        $orderId=dmo_workflow_id($input['order_id']??null);
        $deadline=dmo_orders_validate_agreed_date($input['agreed_deadline']);
    } elseif ($action === 'set_review_status') {
        if (count($input) !== 4 || array_diff(array_keys($input),
            ['action','request_id','expected_status','status']) !== [] ||
            !in_array($input['expected_status'] ?? null,['submitted','clarification_needed'],true) ||
            !in_array($input['status'] ?? null,['submitted','clarification_needed','rejected'],true)) {
            dmo_json(422,['ok'=>false,'error'=>'invalid_review_transition']);
        }
        if (!dmo_quote_policy_ready()) dmo_json(503,['ok'=>false,'error'=>'review_status_disabled']);
    } elseif ($action === 'add_private_note' || $action === 'send_customer_message') {
        if (array_diff(array_keys($input), ['action', 'request_id', 'body','operation_key']) !== []) {
            dmo_json(422, ['ok' => false, 'error' => 'unexpected_fields']);
        }
        $messageKey=dmo_message_operation_key($input);
        $note = dmo_workflow_text($input['body'] ?? null,
            $action === 'send_customer_message' ? 3000 : 5000,
            $action === 'send_customer_message' ? 2 : 1);
        if ($action === 'send_customer_message' && !dmo_workflow_messaging_enabled()) {
            dmo_json(503, ['ok' => false, 'error' => 'customer_messaging_disabled']);
        }
    } else {
        if (array_diff(array_keys($input), [
            'action', 'request_id', 'currency', 'scope',
            'revision_allowance', 'lines','operation_key',
        ]) !== []) {
            dmo_json(422, ['ok' => false, 'error' => 'unexpected_fields']);
        }
        $quoteKey=dmo_message_operation_key($input);
        $quote = dmo_workflow_quote_input($input);
    }

    $db->beginTransaction();
    try {
        // Lock the parent request to serialize internal-note conversation
        // creation and quote version numbering across simultaneous admins.
        $lock = $db->prepare(
            'SELECT status FROM dmo_commission_requests WHERE id = :id FOR UPDATE'
        );
        $lock->execute(['id' => $requestId]);
        $currentStatus = $lock->fetchColumn();
        if ($currentStatus === false) {
            $db->rollBack();
            dmo_json(404, ['ok' => false, 'error' => 'request_not_found']);
        }

        if ($action === 'withdraw_quote') {
            $existing=$db->prepare("SELECT id FROM dmo_quotes WHERE request_id=:id AND status='accepted' LIMIT 1");
            $existing->execute(['id'=>$requestId]);
            $order=$db->prepare('SELECT id FROM dmo_orders WHERE request_id=:id LIMIT 1');
            $order->execute(['id'=>$requestId]);
            if ($existing->fetchColumn()!==false || $order->fetchColumn()!==false ||
                !dmo_messages_request_open((string)$currentStatus)) {
                $db->rollBack();
                dmo_json(409,['ok'=>false,'error'=>'quote_withdrawal_conflict']);
            }
            $update=$db->prepare("UPDATE dmo_quotes SET status='superseded' " .
                "WHERE id=:quote AND request_id=:request AND status='sent' AND sent_at IS NOT NULL");
            $update->execute(['quote'=>$withdrawId,'request'=>$requestId]);
            if ($update->rowCount()!==1) {
                $db->rollBack();
                dmo_json(409,['ok'=>false,'error'=>'quote_not_withdrawable']);
            }
            dmo_workflow_audit($db,(int)$admin['user_id'],$requestId,'quote.withdrawn',
                ['quote_id'=>$withdrawId]);
            $db->commit();
            dmo_json(200,['ok'=>true,'quote_id'=>$withdrawId,'status'=>'superseded',
                'payment_collected'=>false,'email_sent'=>false]);
        }

        if ($action === 'set_order_deadline') {
            $order=$db->prepare('SELECT o.id,o.status,o.payment_status,o.agreed_deadline,' . dmo_order_exception_sql() . ' AS exception_action FROM dmo_orders o ' .
                'WHERE o.id=:order_id AND o.request_id=:request_id LIMIT 1 FOR UPDATE');
            $order->execute(['order_id'=>$orderId,'request_id'=>$requestId]);
            $row=$order->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $db->rollBack();
                dmo_json(404,['ok'=>false,'error'=>'order_not_found']);
            }
            $row['payment_status']=dmo_order_exception_status($row['exception_action'])??$row['payment_status'];
            $previous=dmo_orders_date_value($row['agreed_deadline']);
            if (!dmo_messages_request_open((string)$currentStatus) ||
                !dmo_orders_deadline_editable($row) || $previous !== $input['expected_deadline']) {
                $db->rollBack();
                dmo_json(409,['ok'=>false,'error'=>'order_planning_conflict']);
            }
            if ($previous !== $deadline) {
                $db->prepare('UPDATE dmo_orders SET agreed_deadline=:deadline WHERE id=:id')
                    ->execute(['deadline'=>$deadline,'id'=>$orderId]);
                dmo_workflow_audit($db,(int)$admin['user_id'],$requestId,'order.agreed_deadline_changed',
                    ['order_id'=>$orderId,'from'=>$previous,'to'=>$deadline]);
            }
            $db->commit();
            dmo_json(200,['ok'=>true,'agreed_deadline'=>$deadline,'payment_status'=>'unverified']);
        }

        if ($action === 'set_review_status') {
            $q = $db->prepare("SELECT status FROM dmo_quotes WHERE request_id=:id AND status IN ('sent','accepted')");
            $q->execute(['id'=>$requestId]);
            $o = $db->prepare('SELECT id FROM dmo_orders WHERE request_id=:id LIMIT 1');
            $o->execute(['id'=>$requestId]);
            $allowed = dmo_workflow_review_choices((string)$currentStatus,$q->fetchAll(PDO::FETCH_ASSOC),$o->fetchAll(PDO::FETCH_ASSOC));
            if ($currentStatus !== $input['expected_status'] || !in_array($input['status'],$allowed,true)) {
                $db->rollBack();
                dmo_json(409,['ok'=>false,'error'=>'review_status_changed_or_ineligible']);
            }
            $db->prepare('UPDATE dmo_commission_requests SET status=:status WHERE id=:id')
                ->execute(['status'=>$input['status'],'id'=>$requestId]);
            dmo_workflow_audit($db,(int)$admin['user_id'],$requestId,'request.review_status_changed',
                ['from'=>$currentStatus,'to'=>$input['status']]);
            $db->commit();
            dmo_json(200,['ok'=>true,'status'=>$input['status']]);
        }

        if ($action === 'add_private_note' || $action === 'send_customer_message') {
            $internal=$action==='add_private_note';
            $auditAction=$internal?'request.private_note_created':'request.customer_message_sent';
            $prior=dmo_message_replay($db,$requestId,(int)$admin['user_id'],$auditAction,$messageKey,$note,$internal);
            if($prior!==null){$db->commit();dmo_json(200,['ok'=>true,'message_id'=>$prior,'visibility'=>$internal?'internal_only':'customer_visible','already_saved'=>true]);}
            if ($action === 'send_customer_message') {
                $statusQuery = $db->prepare(
                    'SELECT status FROM dmo_commission_requests WHERE id = :id LIMIT 1'
                );
                $statusQuery->execute(['id' => $requestId]);
                if (!dmo_messages_request_open((string) $statusQuery->fetchColumn())) {
                    $db->rollBack();
                    dmo_json(409, ['ok' => false, 'error' => 'conversation_closed']);
                }
                if (!dmo_auth_rate_limit($db, 'artist-reply',
                    (string) $admin['user_id'], 20, 60) ||
                    !dmo_auth_rate_limit($db, 'artist-reply-request',
                        (string) $requestId, 12, 60)) {
                    $db->rollBack();
                    dmo_json(429, ['ok' => false, 'error' => 'too_many_messages']);
                }
            }
            $lookup = $db->prepare(
                'SELECT id FROM dmo_conversations WHERE request_id = :id LIMIT 1'
            );
            $lookup->execute(['id' => $requestId]);
            $conversationId = $lookup->fetchColumn();
            if ($conversationId === false) {
                $create = $db->prepare(
                    'INSERT INTO dmo_conversations (request_id) VALUES (:id)'
                );
                $create->execute(['id' => $requestId]);
                $conversationId = $db->lastInsertId();
            }

            $messageCount = $db->prepare(
                'SELECT COUNT(*) FROM dmo_messages WHERE conversation_id = :id'
            );
            $messageCount->execute(['id' => $conversationId]);
            if ((int) $messageCount->fetchColumn() >= DMO_CONVERSATION_MAX_MESSAGES) {
                $db->rollBack();
                dmo_json(409, ['ok' => false, 'error' => 'conversation_limit']);
            }
            $stmt = $db->prepare(
                'INSERT INTO dmo_messages ' .
                '(conversation_id, sender_id, body, is_internal) ' .
                'VALUES (:conversation, :admin, :body, :internal)'
            );
            $stmt->execute([
                'conversation' => $conversationId,
                'admin' => (int) $admin['user_id'],
                'body' => $note,
                'internal' => $action === 'add_private_note' ? 1 : 0,
            ]);
            $messageId = (int) $db->lastInsertId();
            dmo_workflow_audit(
                $db, (int) $admin['user_id'], $requestId,
                $action === 'add_private_note' ?
                    'request.private_note_created' : 'request.customer_message_sent',
                ['message_id' => $messageId,'operation_key'=>$messageKey]
            );
            $db->commit();
            dmo_json(201, [
                'ok' => true, 'message_id' => $messageId,
                'visibility' => $action === 'add_private_note' ?
                    'internal_only' : 'customer_visible',
            ]);
        }

        $prior=dmo_quote_draft_replay($db,$requestId,(int)$admin['user_id'],$quoteKey,$quote);
        if($prior!==null){$db->commit();dmo_json(200,$prior);}
        if (!dmo_messages_request_open((string)$currentStatus)) {
            $db->rollBack();
            dmo_json(409,['ok'=>false,'error'=>'request_closed']);
        }

        // An accepted quote is immutable: no later draft/price revision may
        // be created through this endpoint.
        $acceptedQuote = $db->prepare(
            "SELECT id FROM dmo_quotes WHERE request_id = :id AND status = 'accepted' LIMIT 1"
        );
        $acceptedQuote->execute(['id' => $requestId]);
        if ($acceptedQuote->fetchColumn() !== false) {
            $db->rollBack();
            dmo_json(409, ['ok' => false, 'error' => 'quote_already_accepted']);
        }
        $countStmt = $db->prepare(
            'SELECT MAX(version_number) FROM dmo_quotes WHERE request_id = :id'
        );
        $countStmt->execute(['id' => $requestId]);
        $version = (int) $countStmt->fetchColumn() + 1;
        if ($version > 30) {
            $db->rollBack();
            dmo_json(422, ['ok' => false, 'error' => 'quote_version_limit']);
        }

        $insert = $db->prepare(
            'INSERT INTO dmo_quotes ' .
            '(request_id, version_number, currency, total_minor, ' .
            'scope_description, revision_allowance, status) ' .
            "VALUES (:request, :version, :currency, :total, :scope, :revisions, 'draft')"
        );
        $insert->execute([
            'request' => $requestId,
            'version' => $version,
            'currency' => $quote['currency'],
            'total' => $quote['total_minor'],
            'scope' => $quote['scope'],
            'revisions' => $quote['revision_allowance'],
        ]);
        $quoteId = (int) $db->lastInsertId();
        $line = $db->prepare(
            'INSERT INTO dmo_quote_lines ' .
            '(quote_id, description, amount_minor, sort_order) ' .
            'VALUES (:quote, :description, :amount, :sort)'
        );
        foreach ($quote['lines'] as $i => $item) {
            $line->execute([
                'quote' => $quoteId,
                'description' => $item['description'],
                'amount' => $item['amount_minor'],
                'sort' => $i,
            ]);
        }
        dmo_workflow_audit(
            $db, (int) $admin['user_id'], $requestId,
            'quote.draft_created',
            ['quote_id' => $quoteId, 'version' => $version,'operation_key'=>$quoteKey,'payload_digest'=>dmo_quote_draft_digest($quote)]
        );
        $db->commit();
        dmo_json(201, [
            'ok' => true,
            'quote_id' => $quoteId,
            'version' => $version,
            'status' => 'draft',
            'total_minor' => $quote['total_minor'],
        ]);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin commission mutation');
    dmo_json(503, ['ok' => false, 'error' => 'admin_service_unavailable']);
}
