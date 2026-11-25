<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/commission-workflow.php';
require_once dirname(__DIR__, 2) . '/app/notifications.php';
require_once dirname(__DIR__, 2) . '/app/deliveries.php';
require_once dirname(__DIR__, 2) . '/app/payments.php';
dmo_reject_non_get();

try {
    $db = dmo_database();
    dmo_admin_require_session($db);
    $id = dmo_workflow_id($_GET['id'] ?? null);
    $full = $_GET['thread'] ?? 'recent';
    if (!is_string($full) || !in_array($full,['recent','full'],true)) {
        dmo_json(422,['ok'=>false,'error'=>'invalid_thread_view']);
    }
    $detail = dmo_workflow_load($db, $id, $full === 'full');
    if ($detail === null) {
        dmo_json(404, ['ok' => false, 'error' => 'request_not_found']);
    }
    foreach ($detail['orders'] as &$order) {
        $order['deadline_editable'] = dmo_messages_request_open((string)$detail['request']['status']) &&
            dmo_orders_deadline_editable($order);
    }
    unset($order);
    dmo_json(200, [
        'ok' => true,
        'detail' => $detail,
        'manual_payments_enabled' => dmo_manual_payments_enabled() && dmo_messages_request_open((string)$detail['request']['status']),
        'payment_records' => dmo_payment_admin_rows($db,$id),
        'artwork_reviews' => dmo_review_orders($db,$id),
        'fulfillment_options' => dmo_fulfillment_options($db,$id),
        'delivery_drafts' => dmo_delivery_drafts($db,$id),
        'delivery_staging_enabled' => dmo_delivery_staging_enabled($db),
        'notification_options' => dmo_notification_options($db,$detail),
        'notifications_enabled' => dmo_notifications_enabled(),
        'admin_writes_enabled' => dmo_workflow_writes_enabled(),
        'order_creation_enabled' => dmo_orders_admin_enabled() &&
            dmo_messages_request_open((string) $detail['request']['status']),
        'quote_sending_enabled' => dmo_quote_delivery_enabled() &&
            dmo_messages_request_open((string) $detail['request']['status']),
        'customer_messaging_enabled' => dmo_workflow_messaging_enabled() &&
            dmo_messages_request_open((string) $detail['request']['status']),
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'admin commission detail');
    dmo_json(503, ['ok' => false, 'error' => 'admin_service_unavailable']);
}
