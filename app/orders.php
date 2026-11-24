<?php
declare(strict_types=1);
require_once __DIR__ . '/quotes.php';

/**
 * Order confirmation is distinct from quote acceptance. No checkout, balance
 * charging or automatic proof of payment exists in this phase.
 */
function dmo_orders_admin_enabled(): bool
{
    return dmo_quote_policy_ready()
        && dmo_config('DMO_ORDER_ADMIN_ENABLED') === '1'
        && dmo_config('DMO_ORDER_TERMS_APPROVED') === '1'
        && dmo_config('DMO_REQUEST_ADMIN_WRITES_ENABLED') === '1';
}

function dmo_orders_customer_rows(PDO $db, int $requestId): array
{
    $query = $db->prepare(
        'SELECT o.id, o.accepted_quote_id, o.status, o.payment_status, ' .
        'o.agreed_deadline, o.created_at, o.updated_at, ' .
        'q.currency, q.total_minor FROM dmo_orders o ' .
        'JOIN dmo_quotes q ON q.id = o.accepted_quote_id AND q.request_id = o.request_id ' .
        'WHERE o.request_id = :request ORDER BY o.id DESC LIMIT 3'
    );
    $query->execute(['request' => $requestId]);
    $orders = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $orders[] = [
            'id' => (int) $row['id'],
            'quote_id' => (int) $row['accepted_quote_id'],
            'status' => (string) $row['status'],
            'payment_status' => (string) $row['payment_status'],
            'agreed_deadline' => $row['agreed_deadline'] === null
                ? null : (string) $row['agreed_deadline'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            'currency' => (string) $row['currency'],
            'total_minor' => (int) $row['total_minor'],
        ];
    }
    return $orders;
}

function dmo_orders_status_label(string $state): string
{
    return match ($state) {
        'awaiting_arrangement' => 'Awaiting payment arrangements',
        'in_progress' => 'Artwork in progress',
        'ready_for_delivery' => 'Ready for delivery',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        default => 'Order under review',
    };
}
