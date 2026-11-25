<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/requests.php';
require_once dirname(__DIR__).'/app/catalog.php';

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET','POST'], true)) {
    header('Allow: GET, POST');
    dmo_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

try {
    $db = dmo_database();
    $session = dmo_customer_require($db);
    $customer = (int) $session['user_id'];

    if ($method === 'GET') {
        $pageText = $_GET['page'] ?? '1';
        if (!is_string($pageText) || !preg_match('/^[1-9][0-9]{0,3}$/D', $pageText)) {
            dmo_json(422, ['ok'=>false,'error'=>'invalid_history_page']);
        }
        $page = (int) $pageText;
        $size = 40;
        $offset = ($page - 1) * $size;
        $stmt = $db->prepare(
            'SELECT r.public_ref, r.subject_type, r.status, ' .
            'r.submitted_at, r.preferred_deadline, r.tier_snapshot_json, t.name AS tier_name, ' . dmo_requests_stage_columns() .
            'FROM dmo_commission_requests r ' .
            'LEFT JOIN dmo_commission_tiers t ON t.id = r.tier_id ' . dmo_requests_stage_joins() .
            'WHERE r.customer_id = :customer ' .
            'ORDER BY r.submitted_at DESC, r.id DESC LIMIT 41 OFFSET ' . $offset
        );
        $stmt->execute(['customer' => $customer]);
        $rows = $stmt->fetchAll();
        $hasMore = count($rows) > $size;
        if ($hasMore) array_pop($rows);
        dmo_json(200, [
            'ok' => true,
            'requests' => array_map('dmo_requests_list_summary', $rows),
            'page' => $page, 'page_size' => $size, 'has_more' => $hasMore && $page < 9999,
            'new_requests_open' => dmo_intake_public_state($db)['requests_open'],
        ]);
    }

    $input = dmo_auth_json_request();
    dmo_auth_verify_csrf($session);
    if (!dmo_requests_open()) {
        dmo_json(503, ['ok' => false, 'error' => 'commissions_closed']);
    }
    $request = dmo_requests_validate($input);
    $ref = dmo_requests_public_ref($customer, $request['key']);
    $db->beginTransaction();
    try {
        if(dmo_intake_control_enabled())dmo_intake_lock($db);
        // Serialize request insertion for each customer, preventing concurrent
        // duplicate retries and limiting request floods.
        $lock = $db->prepare(
            "SELECT id FROM dmo_users WHERE id = :id AND role = 'customer' " .
            "AND account_status = 'active' AND email_verified_at IS NOT NULL " .
            "LIMIT 1 FOR UPDATE"
        );
        $lock->execute(['id' => $customer]);
        if ($lock->fetchColumn() === false) {
            $db->rollBack();
            dmo_json(401, ['ok' => false, 'error' => 'customer_login_required']);
        }

        $prior = $db->prepare(
            'SELECT public_ref FROM dmo_commission_requests ' .
            'WHERE customer_id = :customer AND public_ref = :ref LIMIT 1'
        );
        $prior->execute(['customer' => $customer, 'ref' => $ref]);
        if ($prior->fetchColumn() !== false) {
            $db->commit();
            dmo_json(200, ['ok' => true, 'public_ref' => $ref, 'already_submitted' => true]);
        }

        // Check AFTER idempotent retry lookup; a successful prior submission
        // remains recoverable even when the artist subsequently pauses intake.
        if(dmo_intake_control_enabled()){
            $intake=dmo_intake_state($db);
            if(!$intake['requests_open']){
                $db->rollBack();dmo_json(503,['ok'=>false,'error'=>$intake['reason']==='at_capacity'?'commissions_at_capacity':'commissions_closed']);
            }
        }
        if (!dmo_auth_rate_limit($db, 'commission-submit-ip', '', 12, 60) ||
            !dmo_auth_rate_limit(
                $db, 'commission-submit-customer', (string) $customer, 5, 1440
            )) {
            $db->rollBack();
            dmo_json(429, ['ok' => false, 'error' => 'too_many_requests']);
        }
        $count = $db->prepare(
            'SELECT COUNT(*) FROM dmo_commission_requests ' .
            'WHERE customer_id = :id ' .
            'AND submitted_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)'
        );
        $count->execute(['id' => $customer]);
        if ((int) $count->fetchColumn() >= 3) {
            $db->rollBack();
            dmo_json(429, ['ok' => false, 'error' => 'daily_request_limit']);
        }

        $tierId = $request['tier_id'];
        if(($tierId!==null||$request['addon_ids'])&&!dmo_catalog_approved()){
            $db->rollBack();dmo_json(422,['ok'=>false,'error'=>'catalog_no_longer_available']);
        }
        $tierSnapshot = null;
        if ($tierId !== null) {
            $tier = $db->prepare(
                'SELECT id, name, description, currency, estimate_min_minor, estimate_max_minor ' .
                'FROM dmo_commission_tiers WHERE id = :id AND is_active = 1 LIMIT 1'
            );
            $tier->execute(['id' => $tierId]);
            $tierRow = $tier->fetch();
            if (!is_array($tierRow)||!dmo_catalog_offer_valid($tierRow)) {
                $db->rollBack();
                dmo_json(422, ['ok' => false, 'error' => 'tier_no_longer_available']);
            }
            $tierSnapshot = dmo_requests_snapshot($tierRow);
        }

        $selected = [];
        $selectAddon = $db->prepare(
            'SELECT id, name, description, currency, estimate_min_minor, estimate_max_minor ' .
            'FROM dmo_addons WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        foreach ($request['addon_ids'] as $addonId) {
            $selectAddon->execute(['id' => $addonId]);
            $addon = $selectAddon->fetch();
            if (!is_array($addon)||!dmo_catalog_offer_valid($addon)) {
                $db->rollBack();
                dmo_json(422, ['ok' => false, 'error' => 'addon_no_longer_available']);
            }
            // A configured tier/add-on mapping is authoritative when present.
            if ($tierId !== null) {
                $link = $db->prepare(
                    'SELECT COUNT(*) FROM dmo_tier_addons WHERE tier_id = :tier'
                );
                $link->execute(['tier' => $tierId]);
                if ((int) $link->fetchColumn() > 0) {
                    $allowed = $db->prepare(
                        'SELECT is_available FROM dmo_tier_addons ' .
                        'WHERE tier_id = :tier AND addon_id = :addon LIMIT 1'
                    );
                    $allowed->execute(['tier' => $tierId, 'addon' => $addonId]);
                    if ((int) $allowed->fetchColumn() !== 1) {
                        $db->rollBack();
                        dmo_json(422, ['ok' => false, 'error' => 'addon_not_available_for_tier']);
                    }
                }
            }
            $selected[] = [
                'id' => $addonId, 'snapshot' => dmo_requests_snapshot($addon),
            ];
        }

        $stmt = $db->prepare(
            'INSERT INTO dmo_commission_requests ' .
            '(public_ref, customer_id, tier_id, tier_snapshot_json, ' .
            'subject_type, brief, preferred_contact, preferred_deadline, ' .
            'status, policy_version) VALUES ' .
            "(:ref, :customer, :tier, :snapshot, :subject, :brief, :contact, " .
            ":deadline, 'submitted', :policy)"
        );
        $stmt->execute([
            'ref' => $ref,
            'customer' => $customer,
            'tier' => $tierId,
            'snapshot' => $tierSnapshot,
            'subject' => $request['subject_type'],
            'brief' => $request['brief'],
            'contact' => $request['preferred_contact'],
            'deadline' => $request['preferred_deadline'],
            'policy' => $request['policy_version'],
        ]);
        $requestId = (int) $db->lastInsertId();

        $add = $db->prepare(
            'INSERT INTO dmo_request_addons ' .
            '(request_id, addon_id, addon_snapshot_json) ' .
            'VALUES (:request, :addon, :snapshot)'
        );
        foreach ($selected as $row) {
            $add->execute([
                'request' => $requestId,
                'addon' => $row['id'],
                'snapshot' => $row['snapshot'],
            ]);
        }
        // One conversation per request, initially empty. No messages sent.
        $db->prepare('INSERT INTO dmo_conversations (request_id) VALUES (:id)')
            ->execute(['id' => $requestId]);

        $db->prepare(
            "INSERT INTO dmo_audit_log (actor_id, action, entity_type, entity_id, details_json) " .
            "VALUES (:actor, 'request.submitted', 'commission_request', :request,:details)"
        )->execute(['actor' => $customer, 'request' => $requestId,'details'=>json_encode(['policy_version'=>$request['policy_version'],'policy_sha256'=>$request['policy_sha256']],JSON_THROW_ON_ERROR)]);

        $db->commit();
        dmo_json(201, [
            'ok' => true, 'public_ref' => $ref,
            'status' => 'submitted',
            'message' => 'Your request is received for artist review. No order or payment exists yet.',
        ]);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
} catch (Throwable $error) {
    dmo_log_problem($error, 'customer commission requests');
    dmo_json(503, ['ok' => false, 'error' => 'request_service_unavailable']);
}
