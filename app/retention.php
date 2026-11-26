<?php
declare(strict_types=1);
require_once __DIR__.'/privacy.php';

/**
 * Fixed review horizons, not legal deadlines or approved retention periods.
 * No customer names, emails, keys, IDs or record contents leave this report.
 */
function dmo_retention_report(PDO $db): array
{
    $closed="('cancelled','canceled','rejected','declined','completed','closed','archived','refunded')";
    $queries=[
        'expired_sessions'=>"SELECT COUNT(*) FROM dmo_sessions WHERE expires_at<=UTC_TIMESTAMP()",
        'expired_tokens'=>"SELECT COUNT(*) FROM dmo_auth_tokens WHERE expires_at<=UTC_TIMESTAMP() OR consumed_at IS NOT NULL",
        'expired_rate_limits'=>"SELECT COUNT(*) FROM dmo_rate_limits WHERE expires_at<=UTC_TIMESTAMP()",
        'sessions_expired_30d'=>"SELECT COUNT(*) FROM dmo_sessions WHERE expires_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)",
        'tokens_expired_30d'=>"SELECT COUNT(*) FROM dmo_auth_tokens WHERE expires_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) OR consumed_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)",
        'rate_limits_expired_7d'=>"SELECT COUNT(*) FROM dmo_rate_limits WHERE expires_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)",
        'closed_no_order_365d'=>"SELECT COUNT(*) FROM dmo_commission_requests r WHERE r.status IN $closed AND r.updated_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY) AND NOT EXISTS(SELECT 1 FROM dmo_orders o WHERE o.request_id=r.id)",
        'closed_with_order_365d'=>"SELECT COUNT(*) FROM dmo_commission_requests r WHERE r.status IN $closed AND r.updated_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY) AND EXISTS(SELECT 1 FROM dmo_orders o WHERE o.request_id=r.id)",
        'agreement_records_preserve'=>"SELECT COUNT(*) FROM dmo_quotes WHERE sent_at IS NOT NULL OR status='accepted'",
        'payment_records_preserve'=>"SELECT COUNT(*) FROM dmo_payments",
        'open_payment_holds'=>"SELECT COUNT(*) FROM dmo_orders o WHERE ".dmo_order_exception_sql()."='payment.access_held'",
    ];
    // Age from the original submission, not the most recent review update.
    $caseFrom=" FROM dmo_audit_log a JOIN dmo_users u ON u.id=a.entity_id
      JOIN dmo_audit_log first_event ON first_event.id=COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(a.details_json,'$.case_id')) AS UNSIGNED),a.id)
      AND first_event.entity_type='user' AND first_event.entity_id=u.id AND first_event.action='privacy.case'
      WHERE a.entity_type='user' AND a.action='privacy.case' AND u.role='customer'
      AND a.id=(SELECT MAX(x.id) FROM dmo_audit_log x WHERE x.entity_type='user' AND x.entity_id=u.id AND x.action='privacy.case')
      AND JSON_UNQUOTE(JSON_EXTRACT(a.details_json,'$.status')) IN ('submitted','in_review')";
    $queries['privacy_open_7d']="SELECT COUNT(*)".$caseFrom." AND first_event.created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)";
    $queries['privacy_open_30d']="SELECT COUNT(*)".$caseFrom." AND first_event.created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)";
    if(dmo_refs_schema_ready($db)){
        $queries['closed_reference_files_365d']="SELECT COUNT(*) FROM dmo_request_attachments d JOIN dmo_commission_requests r ON r.id=d.request_id WHERE r.status IN $closed AND r.updated_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY) AND d.created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY)";
    }
    if(dmo_delivery_schema_ready($db)){
        $queries['closed_unreleased_drafts_365d']="SELECT COUNT(*) FROM dmo_delivery_drafts d JOIN dmo_commission_requests r ON r.id=d.request_id WHERE r.status IN $closed AND r.updated_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY) AND d.created_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 365 DAY) AND NOT EXISTS(SELECT 1 FROM dmo_deliverables f WHERE f.order_id=d.order_id AND f.storage_key=d.storage_key)";
        $queries['released_delivery_records']="SELECT COUNT(*) FROM dmo_deliverables";
    }
    if(dmo_review_schema_ready($db)&&dmo_delivery_schema_ready($db)){
        $queries['proof_linked_drafts_preserve']="SELECT COUNT(*) FROM dmo_delivery_drafts d WHERE EXISTS(SELECT 1 FROM dmo_artwork_reviews v WHERE v.draft_id=d.id)";
    }
    $counts=[];
    foreach($queries as $key=>$sql)$counts[$key]=(int)$db->query($sql)->fetchColumn();
    return ['mode'=>'review_only','as_of'=>gmdate('c'),'counts'=>$counts];
}

/** Count-only preview of possible future auth housekeeping batches.
 * Never returns row identifiers, SQL commands, files or deletion authorization.
 * Business and media data are deliberately excluded even if old.
 */
function dmo_retention_cleanup_preview(PDO $db,int $limit=100): array
{
    if($limit<1||$limit>1000)throw new InvalidArgumentException('Invalid preview bound');
    $predicates=[
        'sessions'=>"FROM dmo_sessions WHERE expires_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)",
        'tokens'=>"FROM dmo_auth_tokens WHERE expires_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY) OR consumed_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)",
        'rate_limits'=>"FROM dmo_rate_limits WHERE expires_at<=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 7 DAY)",
    ];
    $batches=[];
    foreach($predicates as $class=>$predicate){
        $count=(int)$db->query('SELECT COUNT(*) FROM (SELECT 1 '.$predicate.' LIMIT '.($limit+1).') candidates')->fetchColumn();
        $batches[$class]=['preview_count'=>min($count,$limit),'more_candidates'=>$count>$limit];
    }
    return ['mode'=>'preview_only','as_of'=>gmdate('c'),'batch_limit'=>$limit,'batches'=>$batches,
        'business_records'=>'excluded_manual_review','media'=>'excluded_manual_review','legal_hold_status'=>'not_managed','authorizes_deletion'=>false];
}
