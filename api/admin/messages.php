<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/admin.php';
dmo_reject_non_get();

try {
    $db = dmo_database();
    dmo_admin_require_session($db);
    $stmt = $db->query(
        'SELECT m.id, LEFT(m.body, 800) AS body_excerpt, m.created_at, ' .
        'm.is_internal, u.display_name AS sender_name, ' .
        'r.public_ref AS request_ref FROM dmo_messages m ' .
        'JOIN dmo_users u ON u.id = m.sender_id ' .
        'JOIN dmo_conversations c ON c.id = m.conversation_id ' .
        'JOIN dmo_commission_requests r ON r.id = c.request_id ' .
        'ORDER BY m.created_at DESC, m.id DESC LIMIT 50'
    );
    dmo_json(200, [
        'ok' => true,
        'messages' => $stmt->fetchAll(),
        'readonly' => true,
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin messages');
    dmo_json(503, ['ok' => false, 'error' => 'admin_service_unavailable']);
}
