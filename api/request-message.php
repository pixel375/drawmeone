<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/requests.php';

// Customer-to-artist private text messaging. No uploads, HTML, automated
// email, quote acceptance, charges, or public message discovery.
try {
    $db = dmo_database();
    $customer = dmo_customer_require($db);
    $input = dmo_auth_json_request();
    dmo_auth_verify_csrf($customer);
    if (!dmo_messages_enabled()) {
        dmo_json(503, ['ok' => false, 'error' => 'messaging_disabled']);
    }
    if (array_diff(array_keys($input), ['ref', 'body','operation_key']) !== [] ||
        !array_key_exists('ref',$input)||!array_key_exists('body',$input)) {
        dmo_json(422, ['ok' => false, 'error' => 'unexpected_fields']);
    }
    $key=dmo_message_operation_key($input);
    $ref = $input['ref'] ?? null;
    if (!is_string($ref) || !preg_match('/^DMO-[a-f0-9]{20}$/D', $ref)) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_request_reference']);
    }
    $body = dmo_requests_field($input['body'] ?? null, 2, 3000);
    if (preg_match('//u', $body) !== 1) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_request_text']);
    }
    $db->beginTransaction();
    try {
        // Lock the parent row to serialize conversation creation,
        // closure checks and messages for this request.
        $stmt = $db->prepare(
            'SELECT id, status FROM dmo_commission_requests ' .
            'WHERE public_ref = :ref AND customer_id = :customer LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['ref' => $ref, 'customer' => (int) $customer['user_id']]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($request)) {
            $db->rollBack();
            dmo_json(404, ['ok' => false, 'error' => 'request_not_found']);
        }
        $prior=dmo_message_replay($db,(int)$request['id'],(int)$customer['user_id'],'request.customer_message_sent',$key,$body,false);
        if($prior!==null){$db->commit();dmo_json(200,['ok'=>true,'message_id'=>$prior,'visibility'=>'customer_and_artist','already_saved'=>true]);}
        if (!dmo_messages_request_open((string) $request['status'])) {
            $db->rollBack();
            dmo_json(409, ['ok' => false, 'error' => 'conversation_closed']);
        }
        if (!dmo_auth_rate_limit(
                $db, 'customer-message-ip', '', 20, 60
            ) || !dmo_auth_rate_limit(
                $db, 'customer-message-user', (string) $customer['user_id'], 12, 60
            )) {
            $db->rollBack();
            dmo_json(429, ['ok' => false, 'error' => 'too_many_messages']);
        }

        $conversation = $db->prepare(
            'SELECT id FROM dmo_conversations WHERE request_id = :id LIMIT 1'
        );
        $conversation->execute(['id' => $request['id']]);
        $conversationId = $conversation->fetchColumn();
        if ($conversationId === false) {
            $db->prepare('INSERT INTO dmo_conversations (request_id) VALUES (:id)')
                ->execute(['id' => $request['id']]);
            $conversationId = $db->lastInsertId();
        }
        $count = $db->prepare(
            'SELECT COUNT(*) FROM dmo_messages WHERE conversation_id = :id'
        );
        $count->execute(['id' => $conversationId]);
        if ((int) $count->fetchColumn() >= 500) {
            $db->rollBack();
            dmo_json(409, ['ok' => false, 'error' => 'conversation_limit']);
        }
        $write = $db->prepare(
            'INSERT INTO dmo_messages (conversation_id, sender_id, body, is_internal) ' .
            'VALUES (:conversation, :sender, :body, 0)'
        );
        $write->execute([
            'conversation' => $conversationId,
            'sender' => (int) $customer['user_id'],
            'body' => $body,
        ]);
        $messageId = (int) $db->lastInsertId();
        $audit = $db->prepare(
            "INSERT INTO dmo_audit_log (actor_id, action, entity_type, entity_id, details_json) " .
            "VALUES (:actor, 'request.customer_message_sent', 'commission_request', :entity,:details)"
        );
        $audit->execute([
            'actor' => (int) $customer['user_id'],
            'entity' => (int) $request['id'],
            'details'=>json_encode(['message_id'=>$messageId,'operation_key'=>$key],JSON_THROW_ON_ERROR),
        ]);
        $db->commit();
        dmo_json(201, [
            'ok' => true,
            'message_id' => $messageId,
            'visibility' => 'customer_and_artist',
        ]);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
} catch (Throwable $error) {
    dmo_log_problem($error, 'private customer message');
    dmo_json(503, ['ok' => false, 'error' => 'message_service_unavailable']);
}
