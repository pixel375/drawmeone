<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/commission-workflow.php';

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
    if (!in_array($action, ['add_private_note', 'create_quote_draft', 'send_customer_message','set_review_status'], true)) {
        dmo_json(422, ['ok' => false, 'error' => 'unsupported_action']);
    }

    // Validate EVERYTHING before opening a transaction so no error path
    // exits with an uncommitted transaction.
    $note = null;
    $quote = null;
    if ($action === 'set_review_status') {
        if (count($input) !== 4 || array_diff(array_keys($input),
            ['action','request_id','expected_status','status']) !== [] ||
            !in_array($input['expected_status'] ?? null,['submitted','clarification_needed'],true) ||
            !in_array($input['status'] ?? null,['submitted','clarification_needed','rejected'],true)) {
            dmo_json(422,['ok'=>false,'error'=>'invalid_review_transition']);
        }
        if (!dmo_quote_policy_ready()) dmo_json(503,['ok'=>false,'error'=>'review_status_disabled']);
    } elseif ($action === 'add_private_note' || $action === 'send_customer_message') {
        if (array_diff(array_keys($input), ['action', 'request_id', 'body']) !== []) {
            dmo_json(422, ['ok' => false, 'error' => 'unexpected_fields']);
        }
        $note = dmo_workflow_text($input['body'] ?? null,
            $action === 'send_customer_message' ? 3000 : 5000,
            $action === 'send_customer_message' ? 2 : 1);
        if ($action === 'send_customer_message' && !dmo_workflow_messaging_enabled()) {
            dmo_json(503, ['ok' => false, 'error' => 'customer_messaging_disabled']);
        }
    } else {
        if (array_diff(array_keys($input), [
            'action', 'request_id', 'currency', 'scope',
            'revision_allowance', 'lines',
        ]) !== []) {
            dmo_json(422, ['ok' => false, 'error' => 'unexpected_fields']);
        }
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
            if ((int) $messageCount->fetchColumn() >= 500) {
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
                ['message_id' => $messageId]
            );
            $db->commit();
            dmo_json(201, [
                'ok' => true, 'message_id' => $messageId,
                'visibility' => $action === 'add_private_note' ?
                    'internal_only' : 'customer_visible',
            ]);
        }

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
            ['quote_id' => $quoteId, 'version' => $version]
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
