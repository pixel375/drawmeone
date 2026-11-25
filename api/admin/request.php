<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/commission-workflow.php';
require_once dirname(__DIR__, 2) . '/app/notifications.php';
require_once dirname(__DIR__, 2) . '/app/deliveries.php';
require_once dirname(__DIR__, 2) . '/app/payments.php';
require_once dirname(__DIR__, 2) . '/app/checkout.php';
require_once dirname(__DIR__, 2) . '/app/cancellations.php';
dmo_reject_non_get();

try {
    $db = dmo_database();
    dmo_admin_require_session($db);
    $id = dmo_workflow_id($_GET['id'] ?? null);
    if(isset($_GET['activity_before'])){
        $before=$_GET['activity_before'];
        if(!is_string($before)||!preg_match('/^[1-9][0-9]{0,17}$/D',$before))dmo_json(422,['ok'=>false,'error'=>'invalid_activity_cursor']);
        if(dmo_workflow_request($db,$id)===null)dmo_json(404,['ok'=>false,'error'=>'request_not_found']);
        dmo_json(200,['ok'=>true,'activity'=>dmo_workflow_activity($db,$id,(int)$before)]);
    }
    $full = $_GET['thread'] ?? 'recent';
    if (!is_string($full) || !in_array($full,['recent','full'],true)) {
        dmo_json(422,['ok'=>false,'error'=>'invalid_thread_view']);
    }
    $detail = dmo_workflow_load($db, $id, $full === 'full');
    if ($detail === null) {
        dmo_json(404, ['ok' => false, 'error' => 'request_not_found']);
    }
    $version=(string)$detail['request']['policy_version'];
    $eligible=dmo_policy_request_eligible($db,$id,$version);
    $detail['request']['policy_sha256']=dmo_policy_receipt($db,$id);
    foreach ($detail['orders'] as &$order) {
        $order['can_cancel_unpaid']=dmo_cancellation_enabled()&&dmo_cancel_order_eligible($db,$id,$order['id']);
        $order['deadline_editable'] = dmo_messages_request_open((string)$detail['request']['status']) &&
            dmo_orders_deadline_editable($order);
    }
    unset($order);
    dmo_json(200, [
        'ok' => true,
        'detail' => $detail,
        'policy_eligible'=>$eligible,
        'manual_payments_enabled' => dmo_manual_payments_enabled() && $eligible && dmo_messages_request_open((string)$detail['request']['status']),
        'payment_exceptions_enabled' => dmo_payment_exceptions_enabled(),
        'checkout_links'=>dmo_checkout_rows($db,$id,true),
        'payment_records' => dmo_payment_admin_rows($db,$id),
        'artwork_reviews' => dmo_review_orders($db,$id),
        'fulfillment_options' => dmo_fulfillment_options($db,$id),
        'delivery_drafts' => dmo_delivery_drafts($db,$id),
        'delivery_staging_enabled' => dmo_delivery_staging_enabled($db)&&$eligible,
        'notification_options' => dmo_notification_options($db,$detail),
        'notifications_enabled' => dmo_notifications_enabled(),
        'admin_writes_enabled' => dmo_workflow_writes_enabled(),
        'order_creation_enabled' => dmo_orders_admin_enabled() && $eligible &&
            dmo_messages_request_open((string) $detail['request']['status']),
        'quote_sending_enabled' => dmo_quote_delivery_enabled() && $eligible &&
            dmo_messages_request_open((string) $detail['request']['status']),
        'customer_messaging_enabled' => dmo_workflow_messaging_enabled() &&
            dmo_messages_request_open((string) $detail['request']['status']),
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin commission detail');
    dmo_json(503, ['ok' => false, 'error' => 'admin_service_unavailable']);
}
