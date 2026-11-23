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
    if (!in_array($action, ['add_private_note', 'create_quote_draft'], true)) {
        dmo_json(422, ['ok' => false, 'error' => 'unsupported_action']);
    }

    // Validate EVERYTHING before opening a transaction so no error path
    // exits with an uncommitted transaction.
    $note = null;
    $quote = null;
    if ($action === 'add_private_note') {
        if (array_diff(array_keys($input), ['action', 'request_id', 'body']) !== []) {
            dmo_json(422, ['ok' => false, 'error' => 'unexpected_fields']);
        }
        $note = dmo_workflow_text($input['body'] ?? null, 5000, 1);
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
            'SELECT id FROM dmo_commission_requests WHERE id = :id FOR UPDATE'
        );
        $lock->execute(['id' => $requestId]);
        if ($lock->fetchColumn() === false) {
            $db->rollBack();
            dmo_json(404, ['ok' => false, 'error' => 'request_not_found']);
        }

        if ($action === 'add_private_note') {
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

            $stmt = $db->prepare(
                'INSERT INTO dmo_messages ' .
                '(conversation_id, sender_id, body, is_internal) ' .
                'VALUES (:conversation, :admin, :body, 1)'
            );
            $stmt->execute([
                'conversation' => $conversationId,
                'admin' => (int) $admin['user_id'],
                'body' => $note,
            ]);
            $messageId = (int) $db->lastInsertId();
            dmo_workflow_audit(
                $db, (int) $admin['user_id'], $requestId,
                'request.private_note_created', ['message_id' => $messageId]
            );
            $db->commit();
            dmo_json(201, [
                'ok' => true, 'message_id' => $messageId,
                'visibility' => 'internal_only',
            ]);
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
