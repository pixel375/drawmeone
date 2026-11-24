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
