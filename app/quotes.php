<?php
declare(strict_types=1);
require_once __DIR__ . '/requests.php';

/**
 * Customer-visible quotes remain distinct from orders and payment records.
 * Never expose a draft, never accept a browser-provided price.
 */
function dmo_quote_policy_ready(): bool
{
    return dmo_auth_configured()
        && dmo_config('DMO_PRIVACY_PUBLISHED') === '1'
        && dmo_config('DMO_REQUEST_TERMS_APPROVED') === '1'
        && dmo_requests_policy_version() !== '';
}

function dmo_quote_delivery_enabled(): bool
{
    return dmo_quote_policy_ready()
        && dmo_config('DMO_QUOTE_DELIVERY_ENABLED') === '1'
        && dmo_config('DMO_REQUEST_ADMIN_WRITES_ENABLED') === '1';
}

function dmo_quote_response_enabled(): bool
{
    return dmo_quote_policy_ready()
        && dmo_config('DMO_QUOTE_RESPONSES_ENABLED') === '1';
}

function dmo_quote_can_respond(array $quote, bool $serviceReady, string $requestPolicy): bool
{
    return $serviceReady
        && $requestPolicy !== ''
        && hash_equals(dmo_requests_policy_version(), $requestPolicy)
        && ($quote['status'] ?? null) === 'sent'
        && isset($quote['sent_at']) && $quote['sent_at'] !== null
        && (int) ($quote['is_expired'] ?? 1) === 0;
}

function dmo_quote_customer_rows(PDO $db, int $requestId, string $requestPolicy): array
{
    // Only quotes that have *actually been delivered* to this request owner.
    $query = $db->prepare(
        'SELECT id, version_number, currency, total_minor, scope_description, ' .
        'revision_allowance, validity_ends_at, status, sent_at, accepted_at, ' .
        'CASE WHEN validity_ends_at IS NOT NULL AND ' .
        'validity_ends_at <= UTC_TIMESTAMP() THEN 1 ELSE 0 END AS is_expired ' .
        'FROM dmo_quotes WHERE request_id = :id AND sent_at IS NOT NULL ' .
        "AND status IN ('sent','accepted','declined','superseded') " .
        'ORDER BY version_number DESC LIMIT 30'
    );
    $query->execute(['id' => $requestId]);
    $quotes = $query->fetchAll(PDO::FETCH_ASSOC);
    $lines = $db->prepare(
        'SELECT description, amount_minor FROM dmo_quote_lines ' .
        'WHERE quote_id = :id ORDER BY sort_order, id LIMIT 12'
    );
    $accepted = false;
    foreach ($quotes as $q) {
        if ($q['status'] === 'accepted') {
            $accepted = true;
            break;
        }
    }
    foreach ($quotes as &$q) {
        $q['id'] = (int) $q['id'];
        $q['version_number'] = (int) $q['version_number'];
        $q['total_minor'] = (int) $q['total_minor'];
        $q['revision_allowance'] = (int) $q['revision_allowance'];
        $q['is_expired'] = (bool) $q['is_expired'];
        $q['can_respond'] = !$accepted && dmo_quote_can_respond(
            ['status' => $q['status'], 'sent_at' => $q['sent_at'],
             'is_expired' => $q['is_expired'] ? 1 : 0],
            dmo_quote_response_enabled(), $requestPolicy
        );
        $lines->execute(['id' => $q['id']]);
        $q['lines'] = [];
        foreach ($lines->fetchAll(PDO::FETCH_ASSOC) as $line) {
            $q['lines'][] = [
                'description' => (string) $line['description'],
                'amount_minor' => (int) $line['amount_minor'],
            ];
        }
    }
    unset($q);
    return $quotes;
}
