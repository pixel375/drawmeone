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
            'agreed_deadline' => dmo_orders_date_value($row['agreed_deadline']),
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

/** Only pre-payment scheduling, not a payment or fulfillment transition. */
function dmo_orders_deadline_editable(array $order): bool
{
    return dmo_orders_admin_enabled() &&
        ($order['status'] ?? null) === 'awaiting_arrangement' &&
        ($order['payment_status'] ?? null) === 'unverified';
}

function dmo_orders_date_value(mixed $value): ?string
{
    if (!is_string($value)) return null;
    $date = substr($value,0,10);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) ? $date : null;
}

function dmo_orders_validate_agreed_date(mixed $value): ?string
{
    if ($value === null) return null;
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)) {
        dmo_json(422,['ok'=>false,'error'=>'invalid_agreed_deadline']);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('UTC'));
    $today = new DateTimeImmutable('today',new DateTimeZone('UTC'));
    if ($date === false || $date->format('Y-m-d') !== $value ||
        $date < $today || $date > $today->modify('+2 years')) {
        dmo_json(422,['ok'=>false,'error'=>'invalid_agreed_deadline']);
    }
    return $value;
}
