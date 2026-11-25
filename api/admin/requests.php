<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/admin.php';
require_once dirname(__DIR__, 2) . '/app/requests.php';
dmo_reject_non_get();

try {
    $db = dmo_database();
    dmo_admin_require_session($db);
    $search = $_GET['q'] ?? '';
    $pageText = $_GET['page'] ?? '1';
    $filter = $_GET['filter'] ?? 'all';
    if (!is_string($search) || strlen($search) > 160 ||
        preg_match('/[\x00-\x1f\x7f]/', $search) ||
        !is_string($pageText) || !preg_match('/^[1-9][0-9]{0,3}$/D', $pageText) ||
        !is_string($filter) || !in_array($filter, ['all','review','other','orders','quote_waiting','accepted','due'], true)) {
        dmo_json(422, ['ok'=>false,'error'=>'invalid_request_list_filter']);
    }
    $search = trim($search);
    $page = (int) $pageText;
    $size = 40;
    $where = [];
    $params = [];
    if ($search !== '') {
        // LOCATE treats wildcard characters literally, and values are bound.
        $where[] = '(LOCATE(:ref, r.public_ref) > 0 OR LOCATE(:name, u.display_name) > 0)';
        $params = ['ref'=>$search, 'name'=>$search];
    }
    $review = "r.status IN ('submitted','clarification_needed') AND NOT EXISTS (SELECT 1 FROM dmo_quotes q WHERE q.request_id=r.id AND q.status IN ('sent','accepted')) AND NOT EXISTS (SELECT 1 FROM dmo_orders o WHERE o.request_id=r.id)";
    if ($filter === 'review') $where[] = '(' . $review . ')';
    if ($filter === 'other') $where[] = 'NOT (' . $review . ')';
    $activeOrders = "o.status IN ('awaiting_arrangement','in_progress','ready_for_delivery','queued','drawing','awaiting_feedback','revision')";
    if ($filter === 'orders') $where[] = $activeOrders;
    if ($filter === 'quote_waiting') $where[] = "o.id IS NULL AND vq.status='sent' AND vq.validity_ends_at>UTC_TIMESTAMP()";
    if ($filter === 'accepted') $where[] = "o.id IS NULL AND vq.status='accepted'";
    if ($filter === 'due') $where[] = $activeOrders . " AND o.agreed_deadline IS NOT NULL AND DATE(o.agreed_deadline)<=DATE_ADD(UTC_DATE(),INTERVAL 7 DAY)";
    $from = ' FROM dmo_commission_requests r JOIN dmo_users u ON u.id = r.customer_id ' .
        'LEFT JOIN dmo_commission_tiers t ON t.id = r.tier_id ' . dmo_requests_stage_joins();
    $conditions = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where) . ' ';
    // Limit and offset derive only from validated bounded integers.
    $offset = ($page - 1) * $size;
    $stmt = $db->prepare(
        'SELECT r.id, r.public_ref, r.subject_type, r.status, ' .
        'r.preferred_contact, r.submitted_at, LEFT(r.brief, 500) AS brief_excerpt, ' .
        'u.display_name AS customer_name, r.tier_snapshot_json, t.name AS tier_name, ' . dmo_requests_stage_columns() .
        $from . $conditions . 'ORDER BY r.submitted_at DESC, r.id DESC LIMIT ' .
        ($size + 1) . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > $size;
    if ($hasMore) array_pop($rows);
    dmo_json(200, [
        'ok'=>true, 'requests'=>array_map('dmo_requests_list_summary', $rows), 'readonly'=>true,
        'page'=>$page, 'page_size'=>$size, 'has_more'=>$hasMore && $page < 9999,
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin requests');
    dmo_json(503, ['ok'=>false,'error'=>'admin_service_unavailable']);
}
