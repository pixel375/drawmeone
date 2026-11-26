<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/admin.php';
require_once dirname(__DIR__, 2) . '/app/orders.php';
require_once dirname(__DIR__, 2) . '/app/checkout.php';
require_once dirname(__DIR__,2).'/app/artist-queues.php';
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
        !is_string($filter) || !in_array($filter, ['all','review','other','orders','quote_waiting','quote_expiring','awaiting_evidence','accepted','due','quote_followup','payment_review','payment_hold','checkout_followup','reply_due','overdue','upcoming','revision_due','proof_waiting','needs_schedule','paid_to_start','ready_to_deliver','scheduled','requested_dates'], true)) {
        dmo_json(422, ['ok'=>false,'error'=>'invalid_request_list_filter']);
    }
    $customer=$_GET['customer_id']??null;
    if($customer!==null&&(!is_string($customer)||!preg_match('/^[1-9][0-9]{0,17}$/D',$customer)))dmo_json(422,['ok'=>false,'error'=>'invalid_customer_filter']);
    $fromDate=dmo_artist_schedule_date($_GET['from_date']??null);$toDate=dmo_artist_schedule_date($_GET['to_date']??null);
    if(($fromDate!==null||$toDate!==null)&&(!in_array($filter,['scheduled','requested_dates'],true)||($fromDate!==null&&$toDate!==null&&$fromDate>$toDate)))dmo_json(422,['ok'=>false,'error'=>'invalid_schedule_range']);
    $format=$_GET['format']??'json';
    if(!is_string($format)||!in_array($format,['json','calendar'],true))dmo_json(422,['ok'=>false,'error'=>'invalid_request_list_format']);
    if($format==='calendar'&&($filter!=='scheduled'||$fromDate===null||$toDate===null||(new DateTimeImmutable($fromDate))->diff(new DateTimeImmutable($toDate))->days>365))dmo_json(422,['ok'=>false,'error'=>'calendar_range_required']);
    $scope=$_GET['scope']??'identity';$order=$_GET['sort']??'queue';
    if(!is_string($scope)||!in_array($scope,['identity','brief','package'],true)||!is_string($order)||!in_array($order,['queue','newest','oldest','deadline'],true)||!preg_match('//u',$search))dmo_json(422,['ok'=>false,'error'=>'invalid_request_search_options']);
    $search = trim($search);
    $page = (int) $pageText;
    $size = 40;
    $where = [];
    $params = [];
    if ($search !== '') {
        // LOCATE treats wildcard characters literally, and values are bound.
        if($scope==='brief'){$where[]='LOCATE(:brief,r.brief)>0';$params['brief']=$search;}
        elseif($scope==='package'){
            $where[]="(LOCATE(:subject,REPLACE(r.subject_type,'_',' '))>0 OR LOCATE(:tier,COALESCE(NULLIF(TRIM(CASE WHEN JSON_VALID(r.tier_snapshot_json) THEN JSON_UNQUOTE(JSON_EXTRACT(r.tier_snapshot_json,'$.name')) ELSE NULL END),''),t.name,''))>0)";
            $params=['subject'=>$search,'tier'=>$search];
        }else{$where[]='(LOCATE(:ref, r.public_ref) > 0 OR LOCATE(:name, u.display_name) > 0)';$params=['ref'=>$search,'name'=>$search];}
    }
    if($customer!==null){$where[]="r.customer_id=:customer AND u.role='customer'";$params['customer']=(int)$customer;}
    $review = "r.status IN ('submitted','clarification_needed') AND NOT EXISTS (SELECT 1 FROM dmo_quotes q WHERE q.request_id=r.id AND q.status IN ('sent','accepted')) AND NOT EXISTS (SELECT 1 FROM dmo_orders o WHERE o.request_id=r.id)";
    if ($filter === 'review') $where[] = '(' . $review . ')';
    if ($filter === 'other') $where[] = 'NOT (' . $review . ')';
    $activeOrders = "o.status IN ('awaiting_arrangement','in_progress','ready_for_delivery','queued','drawing','awaiting_feedback','revision')";
    if ($filter === 'checkout_followup') $where[] = dmo_checkout_schema_ready($db) ? dmo_checkout_followup_sql() : '1=0';
    if ($filter === 'payment_review') $where[] = "EXISTS (SELECT 1 FROM dmo_payments p WHERE p.order_id=o.id AND p.status='pending_verification')";
    if ($filter === 'payment_hold') $where[] = dmo_order_exception_sql() . "='payment.access_held'";
    if ($filter === 'orders') $where[] = $activeOrders;
    if ($filter === 'quote_waiting') $where[] = "o.id IS NULL AND vq.status='sent' AND vq.validity_ends_at>UTC_TIMESTAMP()";
    if ($filter === 'quote_followup') $where[] = "o.id IS NULL AND r.status IN ('submitted','clarification_needed') AND ((vq.status='sent' AND vq.validity_ends_at<=UTC_TIMESTAMP()) OR vq.status IN ('declined','superseded'))";
    $queues=dmo_artist_queue_conditions($db);
    if(isset($queues[$filter]))$where[]='('.$queues[$filter].')';
    if ($filter === 'due') $where[] = $activeOrders . " AND o.agreed_deadline IS NOT NULL AND DATE(o.agreed_deadline)<=DATE_ADD(UTC_DATE(),INTERVAL 7 DAY)";
    $dateColumn=$filter==='requested_dates'?'r.preferred_deadline':'o.agreed_deadline';
    if($fromDate!==null){$where[]='DATE('.$dateColumn.')>=:from_date';$params['from_date']=$fromDate;}
    if($toDate!==null){$where[]='DATE('.$dateColumn.')<=:to_date';$params['to_date']=$toDate;}
    $from = ' FROM dmo_commission_requests r JOIN dmo_users u ON u.id = r.customer_id ' .
        'LEFT JOIN dmo_commission_tiers t ON t.id = r.tier_id ' . dmo_requests_stage_joins();
    $conditions = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where) . ' ';
    $sort = in_array($filter,['due','overdue','upcoming'],true) ? 'o.agreed_deadline ASC, r.id ASC' : 'r.submitted_at DESC, r.id DESC';
    if($filter==='quote_expiring')$sort='vq.validity_ends_at ASC,r.id ASC';
    if($filter==='awaiting_evidence')$sort='o.agreed_deadline IS NULL ASC,o.agreed_deadline ASC,r.submitted_at ASC,r.id ASC';
    if($filter==='reply_due')$sort="(SELECT MAX(m.id) FROM dmo_messages m JOIN dmo_conversations c ON c.id=m.conversation_id WHERE c.request_id=r.id AND m.is_internal=0) ASC,r.id ASC";
    if(in_array($filter,['needs_schedule','paid_to_start','ready_to_deliver','revision_due'],true))$sort='o.agreed_deadline IS NULL ASC,o.agreed_deadline ASC,r.submitted_at ASC,r.id ASC';
    if(in_array($filter,['scheduled','requested_dates'],true))$sort=$dateColumn.' ASC,r.id ASC';
    if(!in_array($filter,['scheduled','requested_dates'],true))$sort=match($order){'newest'=>'r.submitted_at DESC,r.id DESC','oldest'=>'r.submitted_at ASC,r.id ASC','deadline'=>'o.agreed_deadline IS NULL ASC,o.agreed_deadline ASC,r.id ASC',default=>$sort};
    if($format==='calendar'){
        $calendar=$db->prepare('SELECT r.public_ref,o.agreed_deadline AS order_deadline'.$from.$conditions.'ORDER BY '.$sort.' LIMIT 501');
        $calendar->execute($params);$events=$calendar->fetchAll();
        if(count($events)>500)dmo_json(422,['ok'=>false,'error'=>'calendar_range_too_large']);
        $body=dmo_artist_agenda_calendar($events);
        header('Content-Type: text/calendar; charset=utf-8');header('Content-Disposition: attachment; filename="drawmeone-delivery-agenda.ics"');
        header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');echo $body;exit;
    }
    // Limit and offset derive only from validated bounded integers.
    $offset = ($page - 1) * $size;
    $db->beginTransaction();
    $count=$db->prepare('SELECT COUNT(*)'.$from.$conditions);
    $count->execute($params);$total=(int)$count->fetchColumn();
    $stmt = $db->prepare(
        'SELECT r.id, r.public_ref, r.subject_type, r.status, ' .
        'r.preferred_contact, r.preferred_deadline, r.submitted_at, LEFT(r.brief, 500) AS brief_excerpt, ' .
        'u.display_name AS customer_name, r.tier_snapshot_json, t.name AS tier_name, ' . dmo_requests_stage_columns() .
        $from . $conditions . 'ORDER BY ' . $sort . ' LIMIT ' .
        ($size + 1) . ' OFFSET ' . $offset
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $hasMore = count($rows) > $size;
    if ($hasMore) array_pop($rows);
    $db->commit();
    dmo_json(200, [
        'ok'=>true, 'requests'=>array_map('dmo_requests_list_summary', $rows), 'readonly'=>true,
        'page'=>$page, 'page_size'=>$size, 'matching_count'=>$total,'has_more'=>$hasMore && $page < 9999,
    ]);
} catch (Throwable $error) {
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    dmo_log_problem($error, 'admin requests');
    dmo_json(503, ['ok'=>false,'error'=>'admin_service_unavailable']);
}
