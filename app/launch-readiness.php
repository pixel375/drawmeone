<?php
declare(strict_types=1);
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/orders.php';
require_once __DIR__ . '/references.php';
require_once __DIR__ . '/backup-health.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/deliveries.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/integrity.php';
require_once __DIR__ . '/retention.php';
require_once __DIR__ . '/checkout.php';
require_once __DIR__ . '/cancellations.php';
require_once __DIR__ . '/design.php';
require_once __DIR__.'/catalog.php';
require_once __DIR__.'/artist-queues.php';
require_once __DIR__.'/config-review.php';

/** Shared read-only dashboard data, with no customer identifiers or secrets. */
function dmo_launch_overview(PDO $db): array
{
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
        'privacy_open' => "SELECT COUNT(*) FROM dmo_audit_log a JOIN dmo_users u ON u.id=a.entity_id WHERE a.entity_type='user' AND a.action='privacy.case' AND u.role='customer' AND a.id=(SELECT MAX(x.id) FROM dmo_audit_log x WHERE x.entity_type='user' AND x.entity_id=u.id AND x.action='privacy.case') AND JSON_UNQUOTE(JSON_EXTRACT(a.details_json,'$.status')) IN ('submitted','in_review')",
        'payment_holds' => "SELECT COUNT(*) FROM dmo_orders o WHERE ".dmo_order_exception_sql()."='payment.access_held'",
        'payments_to_verify' => "SELECT COUNT(*) FROM dmo_payments " .
            "WHERE status = 'pending_verification'",
        'messages_last_seven_days' => "SELECT COUNT(*) FROM dmo_messages " .
            "WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)",
    ];
    if(dmo_checkout_schema_ready($db))$queries['checkout_followup']="SELECT COUNT(*) FROM dmo_orders o JOIN dmo_commission_requests r ON r.id=o.request_id WHERE ".dmo_checkout_followup_sql();
    foreach(dmo_artist_queue_conditions($db) as $key=>$predicate)$queries[$key]="SELECT COUNT(*) FROM dmo_commission_requests r ".dmo_requests_stage_joins()." WHERE ".$predicate;
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
    $retention=dmo_retention_report($db);
    $designPublished=dmo_design_load($db,'published');
    $publicPresentation=dmo_design_presentation()==='published';
    $catalog=dmo_catalog_public($db);
    $configuration=dmo_config_review();
    $media=dmo_artwork_runtime();
    $readiness = [
        ['key'=>'image_processor','label'=>'Image processing runtime','status'=>$media['ready']?'ready':'blocked',
         'detail'=>$media['ready']?'JPEG, PNG and WebP functions available; automatic JPEG orientation '.($media['jpeg_orientation']?'available':'unavailable').'; no upload or disk-write proof':'Required PHP image-processing functions or MIME inspection are unavailable'],
        ['key'=>'configuration_review','label'=>'Private configuration review','status'=>$configuration['issue_count']===0?'ready':'blocked','detail'=>$configuration['issue_count'].' configuration warning(s); review only, never launch approval'],
        ['key'=>'pricing','label'=>'Approved published estimates','status'=>dmo_catalog_approved()&&count($catalog['tiers'])>0?'ready':'blocked',
         'detail'=>count($catalog['tiers']).' valid public packages; custom quotes remain separate'],
        ['key'=>'public_design','label'=>'Published public presentation','status'=>$publicPresentation&&$designPublished['exists']?'ready':'blocked',
         'detail'=>$publicPresentation?($designPublished['exists']?'Published design v'.$designPublished['version'].'; visitor mode override only':'Public defaults active; no artist design published'):'Development Design Lab remains available; public presentation switch is off'],
        ['key'=>'integrity','label'=>'Order, payment and artwork record consistency','status'=>$integrityIssues===0?'ready':'blocked',
         'detail'=>$integrityIssues===0?'No inconsistencies detected in current database checks; private-file and invoice authentication checks run separately':$integrityIssues.' affected records or missing schema checks require operator review'],
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
            dmo_config('DMO_REGISTRATION_ENABLED') === '1' && dmo_policy_current()!==null ? 'ready' : 'blocked',
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
         'detail' => 'Offline verifier implemented; independent ciphertext and recovery/app key custody not configured'],
        ['key'=>'privacy_self_service','label'=>'Customer privacy self-service','status'=>dmo_config('DMO_PRIVACY_SELF_SERVICE_ENABLED')==='1'?'ready':'blocked','detail'=>'Scoped customer export and manual privacy requests; no automated deletion'],
        ['key'=>'payment_exceptions','label'=>'Payment exceptions','status'=>dmo_payment_exceptions_enabled()?'ready':'blocked','detail'=>'Manual dispute holds and external full refund records; no money movement'],
        ['key'=>'manual_payments','label'=>'Manual payment reconciliation','status'=>dmo_manual_payments_enabled()?'ready':'blocked',
         'detail'=>'Requires independently checked receiving-account records and separate owner approval'],
        ['key'=>'cancellations','label'=>'Customer withdrawal and unpaid cancellation','status'=>dmo_cancellation_enabled()?'ready':'blocked','detail'=>'No refunds or paid-order cancellation; requires separately approved cancellation terms'],
        ['key'=>'hosted_checkout','label'=>'External hosted invoice links','status'=>dmo_checkout_enabled($db)?'ready':'blocked','detail'=>'Artist-issued single-order invoices; manual reconciliation remains required'],
        ['key' => 'payments', 'label' => 'Verified payment processing',
         'status' => 'planned',
         'detail' => 'Manual reconciliation implemented; automated provider verification not implemented'],
    ];
    return [
        'ok' => true,
        'readiness' => $readiness,
        'counts' => $counts,
        'intake' => $intake,
        'retention'=>$retention,
        'integrity' => ['checks'=>$integrity,'issue_count'=>$integrityIssues,'scope'=>'database_only'],
        'configuration_review'=>$configuration,
        'launch_evidence'=>dmo_launch_evidence($readiness,$intake,$published,count($catalog['tiers'])),
        'readonly' => true,
        'data_is_live' => true,
    ];
}

/** Report only configuration evidence. Owner sign-offs and live QA are not inferred. */
function dmo_launch_evidence(array $readiness,array $intake,int $artworkCount,int $tierCount): array
{
    $byKey=[];
    foreach($readiness as $check)$byKey[$check['key']]=$check;
    $spec=[
        ['prepare','1. Preparation',['public_design','artwork','pricing','policy','backup','integrity','configuration_review','image_processor'],[
            'Confirm final artwork permissions, service scope, price currency, business policies and artist-approved branding.',
            'Complete independent encrypted backup custody and recovery outside the hosting account.']],
        ['onboarding','2. Controlled onboarding',['mail','accounts'],[
            'Verify a real authorized registration, CAPTCHA, email confirmation, login, password reset and logout.',
            'Verify customer/admin isolation with controlled accounts.']],
        ['intake','3. Request and quote',['requests','messaging','references','quotes','orders','cancellations'],[
            'Open only a bounded test intake window; verify request, quote and unpaid-order lifecycle with an authorized account.']],
        ['payments','4. Payment arrangements',['manual_payments','hosted_checkout','payment_exceptions'],[
            'Select a receiving account/provider and verify the exact invoice amount, currency, account and external closure.',
            'Document manual reconciliation or provider-specific authenticated automatic verification before accepting payments.']],
        ['delivery','5. Delivery and operations',['fulfillment','delivery_drafts','privacy_self_service','image_processor'],[
            'Verify private proof/final delivery, download authorization, financial hold and recovery with controlled accounts.',
            'Review retention, privacy, refunds and incident procedures.']],
        ['opening','6. Public opening',['public_design','artwork','pricing','policy','integrity','backup','configuration_review','image_processor','mail','accounts','requests','quotes','orders','fulfillment','delivery_drafts','privacy_self_service'],[
            'Obtain explicit owner approval, review launch rollback settings, and separately approve search indexing.',
            'Confirm independent recovery and the chosen payment arrangement; review all preceding stages and optional features before opening.',
            'Do not interpret configuration readiness as approval to open.']],
    ];
    $stages=[];
    foreach($spec as [$key,$label,$keys,$manual]){
        $pending=[];
        foreach($keys as $checkKey){
            $check=$byKey[$checkKey]??null;
            if(($check['status']??null)!=='ready')$pending[]=$check['label']??$checkKey;
        }
        $stages[]=['key'=>$key,'label'=>$label,'configuration_ready'=>!$pending,'configuration_pending'=>$pending,
            'external_evidence_required'=>$manual];
    }
    return ['generated_at'=>gmdate('c'),'scope'=>'configuration_only','authorizes_launch'=>false,
        'published_artwork_count'=>$artworkCount,'published_package_count'=>$tierCount,
        'intake_open'=>($intake['requests_open']??false)===true,'stages'=>$stages];
}
