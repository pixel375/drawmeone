<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Phase 8: customer-owned, TEXT-ONLY commission requests.
 * Security/terms/customer-account switches must all be explicitly enabled.
 * No file uploads, payments, quote delivery, or customer messages here.
 */
function dmo_requests_policy_version(): string
{
    $version = dmo_config('DMO_REQUEST_POLICY_VERSION');
    return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{2,39}$/D', $version)
        ? $version : '';
}

function dmo_requests_open(): bool
{
    return dmo_auth_configured()
        && dmo_config('DMO_REQUESTS_ENABLED') === '1'
        && dmo_config('DMO_REQUEST_TERMS_APPROVED') === '1'
        && dmo_config('DMO_PRIVACY_PUBLISHED') === '1'
        && dmo_requests_policy_version() !== '';
}

function dmo_customer_require(PDO $db): array
{
    dmo_auth_require_enabled();
    $session = dmo_auth_current_session($db);
    if ($session === null || ($session['role'] ?? '') !== 'customer') {
        dmo_json(401, ['ok' => false, 'error' => 'customer_login_required']);
    }
    return $session;
}

function dmo_requests_field(mixed $raw, int $min, int $max): string
{
    if (!is_string($raw) || strlen($raw) > $max ||
        preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $raw)) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_request_text']);
    }
    $value = trim($raw);
    if (strlen($value) < $min || strlen($value) > $max) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_request_text']);
    }
    return $value;
}

function dmo_requests_id(mixed $raw, bool $nullable = false): ?int
{
    if ($nullable && $raw === null) return null;
    if (!is_int($raw) || $raw < 1 || $raw > PHP_INT_MAX) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_catalog_id']);
    }
    return $raw;
}

function dmo_requests_deadline(mixed $raw): ?string
{
    if ($raw === null || $raw === '') return null;
    if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw)) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_deadline']);
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('UTC'));
    $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
    if ($date === false || $date->format('Y-m-d') !== $raw ||
        $date <= $today || $date > $today->modify('+2 years')) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_deadline']);
    }
    return $raw;
}

function dmo_requests_validate(array $input): array
{
    $expected = [
        'idempotency_key', 'tier_id', 'addon_ids', 'subject_type',
        'brief', 'preferred_contact', 'preferred_deadline',
        'policy_version', 'acknowledge_review',
    ];
    if (array_diff(array_keys($input), $expected) !== []) {
        dmo_json(422, ['ok' => false, 'error' => 'unexpected_fields']);
    }

    $key = $input['idempotency_key'] ?? null;
    if (!is_string($key) || !preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',
        $key
    )) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_request_key']);
    }
    $subjects = ['original_character','fan_character','portrait','other'];
    $subject = $input['subject_type'] ?? null;
    if (!is_string($subject) || !in_array($subject, $subjects, true)) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_subject']);
    }
    $contact = $input['preferred_contact'] ?? null;
    if (!is_string($contact) || !in_array($contact, ['onsite','email'], true)) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_contact_preference']);
    }
    $addons = $input['addon_ids'] ?? null;
    if (!is_array($addons) || !array_is_list($addons) || count($addons) > 8) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_addons']);
    }
    $ids = [];
    foreach ($addons as $addon) {
        $id = dmo_requests_id($addon);
        if (in_array($id, $ids, true)) {
            dmo_json(422, ['ok' => false, 'error' => 'duplicate_addon']);
        }
        $ids[] = $id;
    }
    $version = $input['policy_version'] ?? null;
    if (!is_string($version) || $version !== dmo_requests_policy_version() ||
        ($input['acknowledge_review'] ?? false) !== true) {
        dmo_json(422, ['ok' => false, 'error' => 'policy_acknowledgement_required']);
    }
    return [
        'key' => $key,
        'tier_id' => dmo_requests_id($input['tier_id'] ?? null, true),
        'addon_ids' => $ids,
        'subject_type' => $subject,
        'brief' => dmo_requests_field($input['brief'] ?? null, 30, 6000),
        'preferred_contact' => $contact,
        'preferred_deadline' => dmo_requests_deadline($input['preferred_deadline'] ?? null),
        'policy_version' => $version,
    ];
}

/** Stable per-customer idempotency reference; protects against retry duplicates. */
function dmo_requests_public_ref(int $customerId, string $key): string
{
    return 'DMO-' . substr(
        hash_hmac('sha256', 'customer-request|' . $customerId . '|' . $key,
            dmo_config('DMO_APP_KEY')),
        0, 20
    );
}

function dmo_requests_snapshot(array $row): string
{
    return json_encode([
        'id' => (int) $row['id'],
        'name' => (string) $row['name'],
        'currency' => (string) $row['currency'],
        'estimate_min_minor' => $row['estimate_min_minor'] === null ?
            null : (int) $row['estimate_min_minor'],
        'estimate_max_minor' => $row['estimate_max_minor'] === null ?
            null : (int) $row['estimate_max_minor'],
    ], JSON_THROW_ON_ERROR);
}

/**
 * Historical catalog labels are immutable at submission. For legacy rows
 * without usable snapshots, use the live label (which may be null).
 * Never return the raw snapshot or estimates to the browser.
 */
function dmo_requests_snapshot_name(mixed $snapshot, mixed $fallback): ?string
{
    if (is_string($snapshot) && strlen($snapshot) <= 16384) {
        $value = json_decode($snapshot, true, 8);
        $name = is_array($value) ? ($value['name'] ?? null) : null;
        if (is_string($name) && trim($name) !== '' && strlen($name) <= 480 &&
            !preg_match('/[\x00-\x1f\x7f]/', $name)) {
            return $name;
        }
    }
    return is_string($fallback) && trim($fallback) !== '' ? $fallback : null;
}

function dmo_requests_historical_tier(array $row): array
{
    $row['tier_name'] = dmo_requests_snapshot_name(
        $row['tier_snapshot_json'] ?? null, $row['tier_name'] ?? null
    );
    unset($row['tier_snapshot_json']);
    return $row;
}

/** Onsite messages can be enabled without reopening new commission intake. */
function dmo_messages_enabled(): bool
{
    return dmo_auth_configured() &&
        dmo_config('DMO_CUSTOMER_MESSAGING_ENABLED') === '1' &&
        dmo_config('DMO_PRIVACY_PUBLISHED') === '1' &&
        dmo_config('DMO_REQUEST_TERMS_APPROVED') === '1';
}

/** Closed requests remain readable but do not accept further messages. */
function dmo_messages_request_open(string $status): bool
{
    return !in_array($status, [
        'cancelled', 'canceled', 'rejected', 'declined', 'completed',
        'closed', 'archived', 'refunded',
    ], true);
}

/** Shared list summary: only released quotes and confirmed order metadata. */
function dmo_requests_stage_joins(): string
{
    return 'LEFT JOIN dmo_orders o ON o.id=(SELECT oo.id FROM dmo_orders oo ' .
        'WHERE oo.request_id=r.id ORDER BY oo.id DESC LIMIT 1) ' .
        'LEFT JOIN dmo_quotes vq ON vq.id=(SELECT qq.id FROM dmo_quotes qq ' .
        "WHERE qq.request_id=r.id AND qq.sent_at IS NOT NULL AND qq.status IN ('sent','accepted','declined','superseded') " .
        "ORDER BY (qq.status='accepted') DESC, qq.version_number DESC LIMIT 1) ";
}

function dmo_requests_stage_columns(): string
{
    return 'o.status AS order_status, o.agreed_deadline AS order_deadline, ' . dmo_order_exception_sql() . ' AS payment_exception, ' .
        'vq.status AS visible_quote_status, ' .
        'CASE WHEN vq.validity_ends_at IS NOT NULL AND vq.validity_ends_at <= UTC_TIMESTAMP() ' .
        'THEN 1 ELSE 0 END AS visible_quote_expired ';
}

function dmo_requests_list_summary(array $row): array
{
    $row=dmo_requests_historical_tier($row);
    $stage=(string)$row['status'];
    if (dmo_messages_request_open($stage)) {
        if (is_string($row['order_status']??null)) {
            $stage=$row['order_status'];
        } elseif (($row['visible_quote_status']??null)==='accepted') {
            $stage='quote_accepted';
        } elseif (($row['visible_quote_status']??null)==='sent') {
            $stage=(int)$row['visible_quote_expired']===1 ? 'quote_expired' : 'quote_available';
        } elseif (($row['visible_quote_status']??null)==='declined') {
            $stage='quote_declined';
        } elseif (($row['visible_quote_status']??null)==='superseded') {
            $stage='quote_inactive';
        }
    }
    $exception=dmo_order_exception_status($row['payment_exception']??null);
    if($exception!==null)$stage=$exception==='on_hold'?'payment_on_hold':'payment_refunded';
    $row['workflow_stage']=$stage;
    unset($row['payment_exception']);
    $row['order_deadline']=is_string($row['order_deadline']??null) ? substr($row['order_deadline'],0,10) : null;
    unset($row['visible_quote_expired']);
    return $row;
}

/** The latest financial exception is derived from immutable audit events. */
function dmo_order_exception_sql(string $alias='o'): string
{
    // Internal aliases only; never passed a request parameter.
    if($alias!=='o')throw new InvalidArgumentException('Invalid order alias');
    return "(SELECT a.action FROM dmo_audit_log a WHERE a.entity_type='commission_request'
        AND a.entity_id=o.request_id AND a.action IN ('payment.access_held','payment.access_restored','payment.full_refund_recorded')
        AND JSON_UNQUOTE(JSON_EXTRACT(a.details_json,'$.order_id'))=CAST(o.id AS CHAR)
        ORDER BY a.id DESC LIMIT 1)";
}
function dmo_order_exception_status(mixed $action): ?string
{
    return match($action){
        'payment.access_held'=>'on_hold',
        'payment.full_refund_recorded'=>'refunded',
        default=>null,
    };
}
