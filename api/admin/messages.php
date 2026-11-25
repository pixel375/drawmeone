<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/admin.php';
require_once dirname(__DIR__,2).'/app/artist-queues.php';
dmo_reject_non_get();

try {
    $db = dmo_database();
    dmo_admin_require_session($db);
    $pageText = $_GET['page'] ?? '1';
    $filter = $_GET['filter'] ?? 'all';
    if (!is_string($pageText) || !preg_match('/^[1-9][0-9]{0,3}$/D',$pageText) ||
        !is_string($filter) || !in_array($filter,['all','customer','artist','internal','reply_due'],true)) {
        dmo_json(422,['ok'=>false,'error'=>'invalid_message_filter']);
    }
    $page=(int)$pageText;
    $offset=($page-1)*50;
    $condition=match($filter){
        'reply_due'=>"WHERE m.is_internal=0 AND u.role='customer' AND r.status IN ('submitted','clarification_needed') AND NOT EXISTS(SELECT 1 FROM dmo_messages later WHERE later.conversation_id=c.id AND later.is_internal=0 AND later.id>m.id) ",
        'customer'=>"WHERE m.is_internal=0 AND u.role='customer' ",
        'artist'=>"WHERE m.is_internal=0 AND u.role='admin' ",
        'internal'=>"WHERE m.is_internal=1 ",
        default=>'',
    };
    $stmt = $db->query(
        'SELECT m.id, LEFT(m.body, 800) AS body_excerpt, m.created_at, ' .
        'm.is_internal, u.display_name AS sender_name, u.role AS sender_role, ' .
        'r.id AS request_id, r.public_ref AS request_ref FROM dmo_messages m ' .
        'JOIN dmo_users u ON u.id = m.sender_id ' .
        'JOIN dmo_conversations c ON c.id = m.conversation_id ' .
        'JOIN dmo_commission_requests r ON r.id = c.request_id ' .
        $condition . 'ORDER BY m.created_at DESC, m.id DESC LIMIT 51 OFFSET ' . $offset
    );
    $rows=$stmt->fetchAll();
    $hasMore=count($rows)>50;
    if($hasMore)array_pop($rows);
    dmo_json(200, [
        'ok' => true,
        'messages' => $rows,
        'page'=>$page,'has_more'=>$hasMore && $page<9999,
        'readonly' => true,
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin messages');
    dmo_json(503, ['ok' => false, 'error' => 'admin_service_unavailable']);
}
