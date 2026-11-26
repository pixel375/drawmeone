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
    $search=$_GET['q']??'';
    if(!is_string($search)||strlen($search)>240||!preg_match('//u',$search)||preg_match('/[\x00-\x1f\x7f]/',$search))dmo_json(422,['ok'=>false,'error'=>'invalid_message_search']);
    $search=trim($search);
    if (!is_string($pageText) || !preg_match('/^[1-9][0-9]{0,3}$/D',$pageText) ||
        !is_string($filter) || !in_array($filter,['all','customer','artist','internal','reply_due'],true)) {
        dmo_json(422,['ok'=>false,'error'=>'invalid_message_filter']);
    }
    $page=(int)$pageText;
    $offset=($page-1)*50;
    $condition=match($filter){
        'reply_due'=>"WHERE m.is_internal=0 AND u.role='customer' AND ".dmo_artist_open_request_sql()." AND NOT EXISTS(SELECT 1 FROM dmo_messages later WHERE later.conversation_id=c.id AND later.is_internal=0 AND later.id>m.id) ",
        'customer'=>"WHERE m.is_internal=0 AND u.role='customer' ",
        'artist'=>"WHERE m.is_internal=0 AND u.role='admin' ",
        'internal'=>"WHERE m.is_internal=1 ",
        default=>'',
    };
    $params=[];
    if($search!==''){
        $condition.=($condition===''?'WHERE ':'AND ').'(LOCATE(:body,m.body)>0 OR LOCATE(:sender,u.display_name)>0 OR LOCATE(:ref,r.public_ref)>0) ';
        $params=['body'=>$search,'sender'=>$search,'ref'=>$search];
    }
    $from='FROM dmo_messages m JOIN dmo_users u ON u.id=m.sender_id JOIN dmo_conversations c ON c.id=m.conversation_id JOIN dmo_commission_requests r ON r.id=c.request_id ';
    $db->beginTransaction();
    $count=$db->prepare('SELECT COUNT(*) '.$from.$condition);$count->execute($params);$total=(int)$count->fetchColumn();
    $stmt = $db->prepare(
        'SELECT m.id, LEFT(m.body, 800) AS body_excerpt, m.created_at, ' .
        'm.is_internal, u.display_name AS sender_name, u.role AS sender_role, ' .
        'r.id AS request_id, r.public_ref AS request_ref ' . $from .
        $condition . ($filter==='reply_due'?'ORDER BY m.id ASC ':'ORDER BY m.created_at DESC, m.id DESC ') . 'LIMIT 51 OFFSET ' . $offset
    );
    $stmt->execute($params);$rows=$stmt->fetchAll();
    $db->commit();
    $hasMore=count($rows)>50;
    if($hasMore)array_pop($rows);
    dmo_json(200, [
        'ok' => true,
        'messages' => $rows,
        'page'=>$page,'matching_count'=>$total,'has_more'=>$hasMore && $page<9999,
        'readonly' => true,
    ]);
} catch (Throwable $error) {
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    dmo_log_problem($error, 'admin messages');
    dmo_json(503, ['ok' => false, 'error' => 'admin_service_unavailable']);
}
