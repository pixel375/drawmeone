<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/admin.php';
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
        !is_string($filter) || !in_array($filter, ['all','review','other'], true)) {
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
    if ($filter === 'review') $where[] = "r.status IN ('submitted','clarification_needed')";
    if ($filter === 'other') $where[] = "r.status NOT IN ('submitted','clarification_needed')";
    $from = ' FROM dmo_commission_requests r JOIN dmo_users u ON u.id = r.customer_id ' .
        'LEFT JOIN dmo_commission_tiers t ON t.id = r.tier_id ';
    $conditions = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where) . ' ';
    // Limit and offset derive only from validated bounded integers.
    $offset = ($page - 1) * $size;
    $stmt = $db->prepare(
        'SELECT r.id, r.public_ref, r.subject_type, r.status, ' .
        'r.preferred_contact, r.submitted_at, LEFT(r.brief, 500) AS brief_excerpt, ' .
        'u.display_name AS customer_name, t.name AS tier_name ' .
        $from . $conditions . 'ORDER BY r.submitted_at DESC, r.id DESC LIMIT ' .
        ($size + 1) . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > $size;
    if ($hasMore) array_pop($rows);
    dmo_json(200, [
        'ok'=>true, 'requests'=>$rows, 'readonly'=>true,
        'page'=>$page, 'page_size'=>$size, 'has_more'=>$hasMore && $page < 9999,
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin requests');
    dmo_json(503, ['ok'=>false,'error'=>'admin_service_unavailable']);
}
