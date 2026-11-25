<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/admin.php';
require_once dirname(__DIR__, 2) . '/app/orders.php';
require_once dirname(__DIR__, 2) . '/app/references.php';
require_once dirname(__DIR__, 2) . '/app/backup-health.php';
require_once dirname(__DIR__, 2) . '/app/notifications.php';
require_once dirname(__DIR__, 2) . '/app/deliveries.php';
require_once dirname(__DIR__, 2) . '/app/payments.php';
require_once dirname(__DIR__, 2) . '/app/integrity.php';
dmo_reject_non_get();

try {
    $db = dmo_database();
    dmo_admin_require_session($db);
    $queries = [
        'requests_for_review' => "SELECT COUNT(*) FROM dmo_commission_requests r " .
            "WHERE r.status IN ('submitted','clarification_needed') AND NOT EXISTS (SELECT 1 FROM dmo_quotes q WHERE q.request_id=r.id AND q.status IN ('sent','accepted')) AND NOT EXISTS (SELECT 1 FROM dmo_orders o WHERE o.request_id=r.id)",
        'quotes_to_follow_up' => "SELECT COUNT(*) FROM dmo_commission_requests r " .
            dmo_requests_stage_joins() .
            "WHERE o.id IS NULL AND r.status IN ('submitted','clarification_needed') AND ((vq.status='sent' AND vq.validity_ends_at<=UTC_TIMESTAMP()) OR vq.status IN ('declined','superseded'))",
        'active_orders' => "SELECT COUNT(*) FROM dmo_orders " .
            "WHERE status IN ('awaiting_arrangement','in_progress','ready_for_delivery'," .
            "'queued','drawing','awaiting_feedback','revision')",
        'orders_due_soon' => "SELECT COUNT(*) FROM dmo_orders WHERE status IN ('awaiting_arrangement','in_progress','ready_for_delivery','queued','drawing','awaiting_feedback','revision') AND agreed_deadline IS NOT NULL AND DATE(agreed_deadline)<=DATE_ADD(UTC_DATE(),INTERVAL 7 DAY)",
        'payment_holds' => "SELECT COUNT(*) FROM dmo_orders o WHERE ".dmo_order_exception_sql()."='payment.access_held'",
        'payments_to_verify' => "SELECT COUNT(*) FROM dmo_payments " .
            "WHERE status = 'pending_verification'",
        'messages_last_seven_days' => "SELECT COUNT(*) FROM dmo_messages " .
            "WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)",
    ];
    $counts = [];
    foreach ($queries as $name => $sql) {
        $counts[$name] = (int) $db->query($sql)->fetchColumn();
    }
    // Administrative launch readiness: safe booleans and counts only.
    // No secret values, environment paths, user data or private file names.
    $published = (int) $db->query(
        "SELECT COUNT(*) FROM dmo_portfolio_artworks WHERE visibility = 'published'"
    )->fetchColumn();
    $localBackup = dmo_backup_local_health();
    $policyReady = dmo_quote_policy_ready();
    $intake=dmo_intake_state($db);
    $integrity=dmo_integrity_records($db);
    $integrityIssues=array_sum($integrity);
    $readiness = [
        ['key'=>'integrity','label'=>'Order and payment record consistency','status'=>$integrityIssues===0?'ready':'blocked',
         'detail'=>$integrityIssues===0?'No inconsistencies detected in current database checks; file checks run separately':$integrityIssues.' failed record checks require operator review'],
        ['key'=>'review_schema','label'=>'Artwork review schema','status'=>dmo_review_schema_ready($db)?'ready':'blocked','detail'=>'Required for optional proof approval and included revisions'],
        ['key'=>'review','label'=>'Customer proof review','status'=>dmo_review_enabled($db)?'ready':'blocked','detail'=>'Optional watermarked proof and quote-based revision allowances'],
        ['key' => 'mail', 'label' => 'Transactional email',
         'status' => dmo_mail_ready() ? 'ready' : 'blocked',
         'detail' => 'Required for verification and password reset'],
        ['key' => 'artwork', 'label' => 'Published portfolio',
         'status' => $published > 0 ? 'ready' : 'blocked',
         'detail' => $published . ' published drawings'],
        ['key' => 'policy', 'label' => 'Approved Terms and Privacy',
         'status' => $policyReady ? 'ready' : 'blocked',
         'detail' => 'Requires owner-approved published legal documents and policy version'],
        ['key' => 'accounts', 'label' => 'Customer accounts',
         'status' => dmo_auth_configured() &&
            dmo_config('DMO_REGISTRATION_ENABLED') === '1' ? 'ready' : 'blocked',
         'detail' => 'Registration stays closed until controlled account QA'],
        ['key' => 'requests', 'label' => 'Commission submissions',
         'status' => $intake['requests_open'] ? 'ready' : 'blocked',
         'detail' => 'Requires verified accounts and approved policies'],
        ['key' => 'messaging', 'label' => 'Private messaging',
         'status' => dmo_messages_enabled() ? 'ready' : 'blocked',
         'detail' => 'Artist/customer messages behind independent access gates'],
        ['key' => 'references', 'label' => 'Customer reference uploads',
         'status' => dmo_refs_enabled($db) ? 'ready' : 'blocked',
         'detail' => 'Private images and customer ownership checks'],
        ['key' => 'quotes', 'label' => 'Quote release and acceptance',
         'status' => dmo_quote_delivery_enabled() &&
             dmo_quote_response_enabled() ? 'ready' : 'blocked',
         'detail' => 'Requires both independent quote switches'],
        ['key' => 'orders', 'label' => 'Order confirmation',
         'status' => dmo_orders_admin_enabled() ? 'ready' : 'blocked',
         'detail' => 'Unpaid confirmation and individually agreed scheduling; no payment verification'],
        ['key'=>'fulfillment','label'=>'Paid order production and delivery','status'=>dmo_fulfillment_enabled($db)?'ready':'blocked','detail'=>'Requires reconciled full payment and approved delivery terms'],
        ['key'=>'delivery_drafts','label'=>'Private artwork staging',
         'status'=>dmo_delivery_staging_enabled($db)?'ready':'blocked',
         'detail'=>'Private staging, separate explicit final release'],
        ['key' => 'notifications', 'label' => 'Customer email notices',
         'status' => dmo_notifications_enabled() ? 'ready' : 'blocked',
         'detail' => 'Optional artist-triggered notices to verified email-preferring customers; no automated sends'],
        ['key' => 'backup', 'label' => 'Encrypted local backup',
         'status' => $localBackup['ready'] ? 'ready' : 'blocked',
         'detail' => $localBackup['ready']
             ? $localBackup['count'] . ' private snapshot(s); latest under 36 hours. Metadata check only'
             : 'Backup freshness, file permissions or recovery-key presence needs attention'],
        ['key' => 'offsite', 'label' => 'Independent off-site recovery',
         'status' => 'planned',
         'detail' => 'Off-site copy and separately stored recovery key not configured'],
        ['key'=>'payment_exceptions','label'=>'Payment exceptions','status'=>dmo_payment_exceptions_enabled()?'ready':'blocked','detail'=>'Manual dispute holds and external full refund records; no money movement'],
        ['key'=>'manual_payments','label'=>'Manual payment reconciliation','status'=>dmo_manual_payments_enabled()?'ready':'blocked',
         'detail'=>'Requires independently checked receiving-account records and separate owner approval'],
        ['key' => 'payments', 'label' => 'Verified payment processing',
         'status' => 'planned',
         'detail' => 'Manual reconciliation implemented; automated provider verification not implemented'],
    ];
    dmo_json(200, [
        'ok' => true,
        'readiness' => $readiness,
        'counts' => $counts,
        'intake' => $intake,
        'integrity' => ['checks'=>$integrity,'issue_count'=>$integrityIssues,'scope'=>'database_only'],
        'readonly' => true,
        'data_is_live' => true,
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin overview');
    dmo_json(503, ['ok' => false, 'error' => 'admin_service_unavailable']);
}
