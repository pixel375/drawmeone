<?php
declare(strict_types=1);
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/requests.php';
require_once __DIR__ . '/references.php';
require_once __DIR__ . '/orders.php';

/**
 * Admin review helpers. Quote release and customer responses are independent,
 * policy-gated endpoints; no action here creates orders or payments.
 */
function dmo_workflow_writes_enabled(): bool
{
    return dmo_config('DMO_REQUEST_ADMIN_WRITES_ENABLED') === '1';
}

function dmo_workflow_messaging_enabled(): bool
{
    return dmo_workflow_writes_enabled() && dmo_messages_enabled();
}

function dmo_workflow_id(mixed $value): int
{
    if (is_string($value) && preg_match('/^[1-9][0-9]{0,17}$/D', $value)) {
        $value = (int) $value;
    }
    if (!is_int($value) || $value < 1) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_request_id']);
    }
    return $value;
}

function dmo_workflow_text(mixed $value, int $max, int $min = 1): string
{
    if (!is_string($value) || strlen($value) > $max ||
        preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_text']);
    }
    $text = trim($value);
    if (strlen($text) < $min || strlen($text) > $max) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_text']);
    }
    return $text;
}

function dmo_workflow_request(PDO $db, int $id): ?array
{
    $stmt = $db->prepare(
        'SELECT r.id, r.public_ref, r.subject_type, r.brief, r.status, ' .
        'r.preferred_contact, r.preferred_deadline, r.policy_version, ' .
        'r.submitted_at, r.updated_at, r.tier_id, r.tier_snapshot_json, ' .
        'u.display_name AS customer_name, u.email AS customer_email, ' .
        't.name AS tier_name FROM dmo_commission_requests r ' .
        'JOIN dmo_users u ON u.id = r.customer_id ' .
        'LEFT JOIN dmo_commission_tiers t ON t.id = r.tier_id ' .
        'WHERE r.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return is_array($row) ? dmo_requests_historical_tier($row) : null;
}

function dmo_workflow_review_choices(string $status, array $quotes, array $orders): array
{
    if (!dmo_workflow_writes_enabled() || !dmo_quote_policy_ready() || $orders !== []) return [];
    foreach ($quotes as $quote) {
        if (in_array($quote['status'] ?? '', ['sent','accepted'], true)) return [];
    }
    return match ($status) {
        'submitted' => ['clarification_needed','rejected'],
        'clarification_needed' => ['submitted','rejected'],
        default => [],
    };
}

/** Private, bounded event history. Never return raw audit JSON, identifiers or actor names. */
function dmo_workflow_activity(PDO $db,int $id): array
{
    $labels=[
        'request.submitted'=>'Commission submitted','request.review_status_changed'=>'Review status changed',
        'request.customer_withdrawn'=>'Customer withdrew request','request.private_note_created'=>'Private artist note added',
        'request.customer_message_sent'=>'Private conversation updated','request.reference_uploaded'=>'Reference image uploaded',
        'request.reference_deleted'=>'Reference image removed','quote.draft_created'=>'Quote draft created',
        'quote.published_to_customer'=>'Quote released to customer','quote.withdrawn'=>'Quote withdrawn',
        'quote.customer_accepted'=>'Customer accepted quote','quote.customer_declined'=>'Customer declined quote',
        'order.confirmed_unpaid'=>'Unpaid order confirmed','order.agreed_deadline_changed'=>'Agreed date changed',
        'order.production_changed'=>'Production status changed','order.unpaid_cancelled'=>'Unpaid order cancelled',
        'payment.manual_pending_verification'=>'Payment evidence recorded','payment.manual_verified'=>'Payment evidence verified by artist',
        'payment.manual_rejected'=>'Payment evidence rejected','payment.access_held'=>'Payment access held',
        'payment.access_restored'=>'Payment access restored','payment.full_refund_recorded'=>'External full refund recorded',
        'checkout.active'=>'Hosted invoice recorded','checkout.revoked'=>'Hosted invoice revoked locally',
        'delivery.draft_staged'=>'Artwork staged privately','delivery.draft_removed'=>'Private artwork draft removed',
        'artwork.review_offered'=>'Watermarked proof offered','artwork.review_approved'=>'Proof approved',
        'artwork.review_revision_requested'=>'Revision requested','delivery.released'=>'Final artwork released',
        'notification.reserved'=>'Email notice attempt reserved','notification.accepted'=>'Mail server accepted notice',
        'notification.failed'=>'Mail notice not confirmed',
    ];
    $placeholders=implode(',',array_fill(0,count($labels),'?'));
    $q=$db->prepare("SELECT action,created_at FROM dmo_audit_log WHERE entity_type='commission_request' AND entity_id=? AND action IN ($placeholders) ORDER BY id DESC LIMIT 51");
    $q->execute(array_merge([$id],array_keys($labels)));
    $rows=$q->fetchAll();$older=count($rows)>50;
    if($older)array_pop($rows);
    return ['events'=>array_map(static fn($r)=>['label'=>$labels[$r['action']],'created_at'=>$r['created_at']],$rows),'has_older'=>$older];
}

function dmo_workflow_load(PDO $db, int $id, bool $fullThread = false): ?array
{
    $request = dmo_workflow_request($db, $id);
    if ($request === null) {
        return null;
    }
    $addons = $db->prepare(
        'SELECT a.name, ra.addon_snapshot_json FROM dmo_request_addons ra ' .
        'JOIN dmo_addons a ON a.id = ra.addon_id ' .
        'WHERE ra.request_id = :id ORDER BY a.sort_order, a.id LIMIT 50'
    );
    $addons->execute(['id' => $id]);
    $extra = [];
    foreach ($addons->fetchAll() as $row) {
        // No unfiltered snapshot JSON is returned to the browser.
        $name = dmo_requests_snapshot_name($row['addon_snapshot_json'], $row['name']);
        if ($name !== null) $extra[] = ['name' => $name];
    }

    $messages = $db->prepare(
        'SELECT m.id, m.body, m.is_internal, m.created_at, ' .
        'u.display_name AS sender_name, u.role AS sender_role ' .
        'FROM dmo_conversations c JOIN dmo_messages m ON m.conversation_id = c.id ' .
        'JOIN dmo_users u ON u.id = m.sender_id ' .
        'WHERE c.request_id = :id ORDER BY m.id DESC LIMIT ' . ($fullThread ? '500' : '101')
    );
    $messages->execute(['id' => $id]);
    $thread = $messages->fetchAll();
    $hasOlder = !$fullThread && count($thread) > 100;
    if ($hasOlder) array_pop($thread);
    $thread = array_reverse($thread);
    foreach ($thread as &$m) {
        $m['is_internal'] = (bool) $m['is_internal'];
    }
    unset($m);

    $quotesQuery = $db->prepare(
        'SELECT id, version_number, currency, total_minor, scope_description, ' .
        'revision_allowance, validity_ends_at, status, sent_at, accepted_at, created_at, ' .
        'CASE WHEN validity_ends_at IS NOT NULL AND validity_ends_at<=UTC_TIMESTAMP() THEN 1 ELSE 0 END AS is_expired ' .
        'FROM dmo_quotes WHERE request_id = :id ' .
        'ORDER BY version_number DESC LIMIT 30'
    );
    $quotesQuery->execute(['id' => $id]);
    $quotes = $quotesQuery->fetchAll();
    $lineStmt = $db->prepare(
        'SELECT description, amount_minor, sort_order FROM dmo_quote_lines ' .
        'WHERE quote_id = :id ORDER BY sort_order, id LIMIT 20'
    );
    foreach ($quotes as &$q) {
        $lineStmt->execute(['id' => $q['id']]);
        $q['lines'] = $lineStmt->fetchAll();
        $q['id'] = (int) $q['id'];
        $q['is_expired'] = (bool) $q['is_expired'];
        $q['version_number'] = (int) $q['version_number'];
        $q['total_minor'] = (int) $q['total_minor'];
        $q['revision_allowance'] = (int) $q['revision_allowance'];
        foreach ($q['lines'] as &$line) {
            $line['amount_minor'] = (int) $line['amount_minor'];
        }
        unset($line);
    }
    unset($q);
    $orders = dmo_orders_customer_rows($db, $id);
    return [
        'review_choices' => dmo_workflow_review_choices((string)$request['status'], $quotes, $orders),
        'messages_have_older' => $hasOlder,
        'activity'=>dmo_workflow_activity($db,$id),
        'request' => $request,
        'addons' => $extra,
        'messages' => $thread,
        'quotes' => $quotes,
        'references' => dmo_refs_metadata($db, $id),
        'orders' => $orders,
    ];
}

function dmo_workflow_audit(PDO $db, int $adminId, int $requestId, string $action, array $detail): void
{
    $stmt = $db->prepare(
        "INSERT INTO dmo_audit_log " .
        "(actor_id, action, entity_type, entity_id, details_json) " .
        "VALUES (:actor, :action, 'commission_request', :request, :details)"
    );
    $stmt->execute([
        'actor' => $adminId, 'action' => $action, 'request' => $requestId,
        'details' => json_encode($detail, JSON_THROW_ON_ERROR),
    ]);
}

function dmo_workflow_quote_input(array $input): array
{
    $currency = $input['currency'] ?? null;
    if (!is_string($currency) || !in_array($currency, ['CAD', 'USD'], true)) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_currency']);
    }
    $scope = dmo_workflow_text($input['scope'] ?? null, 5000, 10);
    $revisions = $input['revision_allowance'] ?? null;
    if (!is_int($revisions) || $revisions < 0 || $revisions > 25) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_revisions']);
    }
    $raw = $input['lines'] ?? null;
    if (!is_array($raw) || !array_is_list($raw) ||
        count($raw) < 1 || count($raw) > 12) {
        dmo_json(422, ['ok' => false, 'error' => 'invalid_quote_lines']);
    }
    $lines = [];
    $sum = 0;
    foreach ($raw as $line) {
        if (!is_array($line) || array_is_list($line) ||
            array_diff(array_keys($line), ['description', 'amount_minor']) !== []) {
            dmo_json(422, ['ok' => false, 'error' => 'invalid_quote_line']);
        }
        $description = dmo_workflow_text($line['description'] ?? null, 180, 2);
        $amount = $line['amount_minor'] ?? null;
        if (!is_int($amount) || $amount < 1 || $amount > 10000000) {
            dmo_json(422, ['ok' => false, 'error' => 'invalid_quote_amount']);
        }
        $sum += $amount;
        if ($sum > 10000000) {
            dmo_json(422, ['ok' => false, 'error' => 'quote_total_too_large']);
        }
        $lines[] = ['description' => $description, 'amount_minor' => $amount];
    }
    return [
        'currency' => $currency,
        'scope' => $scope,
        'revision_allowance' => $revisions,
        'lines' => $lines,
        'total_minor' => $sum,
    ];
}

/** Read an existing quote operation under the caller's locked request row. */
function dmo_quote_draft_replay(PDO $db,int $request,int $actor,?string $key,array $quote): ?array
{
    if($key===null)return null;
    $digest=dmo_quote_draft_digest($quote);
    $q=$db->prepare("SELECT JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.quote_id')) AS quote_id,
        JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.payload_digest')) AS digest
        FROM dmo_audit_log WHERE entity_type='commission_request' AND entity_id=:request
        AND actor_id=:actor AND action='quote.draft_created'
        AND JSON_UNQUOTE(JSON_EXTRACT(details_json,'$.operation_key'))=:operation ORDER BY id DESC LIMIT 1");
    $q->execute(['request'=>$request,'actor'=>$actor,'operation'=>$key]);
    $row=$q->fetch();
    if(!is_array($row))return null;
    if(!is_string($row['digest'])||!hash_equals($row['digest'],$digest)){
        $db->rollBack();dmo_json(409,['ok'=>false,'error'=>'operation_key_conflict']);
    }
    $q=$db->prepare('SELECT id,version_number,status,total_minor FROM dmo_quotes WHERE id=:id AND request_id=:request');
    $q->execute(['id'=>$row['quote_id'],'request'=>$request]);$saved=$q->fetch();
    if(!is_array($saved)){
        $db->rollBack();dmo_json(409,['ok'=>false,'error'=>'quote_receipt_unavailable']);
    }
    return ['ok'=>true,'quote_id'=>(int)$saved['id'],'version'=>(int)$saved['version_number'],
        'status'=>$saved['status'],'total_minor'=>(int)$saved['total_minor'],'already_saved'=>true];
}
function dmo_quote_draft_digest(array $quote): string
{
    return hash_hmac('sha256','quote-draft|'.json_encode($quote,JSON_THROW_ON_ERROR),dmo_config('DMO_APP_KEY'));
}
