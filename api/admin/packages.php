<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/app/packages.php';
require_once dirname(__DIR__, 2) . '/app/catalog.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    dmo_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

try {
    $db = dmo_database();
    $admin = dmo_admin_require_session($db);

    if ($method === 'GET') {
        dmo_json(200, [
            'ok' => true,
            'catalog' => dmo_packages_list($db),
            'write_enabled' => dmo_packages_writes_enabled(),
            'publish_enabled' => dmo_packages_publish_enabled(),
            'currency_note' => 'CAD and USD are supported; rates must be artist approved.',
        ]);
    }

    // Authoritative flags are read on every write, never trusted from JS.
    if (!dmo_packages_writes_enabled()) {
        dmo_json(503, ['ok' => false, 'error' => 'package_editing_disabled']);
    }
    $input = dmo_auth_json_request();
    dmo_auth_verify_csrf($admin);
    $action = $input['action'] ?? null;

    if (!in_array($action, ['save_tier','save_addon','set_active'], true)) {
        dmo_package_fail('invalid_action');
    }

    $kind = $action === 'save_tier' ? 'tier' :
        ($action === 'save_addon' ? 'addon' : ($input['kind'] ?? ''));
    if (!in_array($kind, ['tier', 'addon'], true)) {
        dmo_package_fail('invalid_kind');
    }
    $table = $kind === 'tier' ? 'dmo_commission_tiers' : 'dmo_addons';

    $fields='id,slug,name,description,currency,estimate_min_minor,estimate_max_minor,sort_order,is_active'.($kind==='tier'?',features_json':'');
    $db->beginTransaction();
    try {
        if ($action === 'set_active') {
            if (!dmo_packages_publish_enabled()) {
                $db->rollBack();
                dmo_json(503, ['ok' => false, 'error' => 'package_publishing_disabled']);
            }
            $id = dmo_package_id($input['id'] ?? null, false);
            $active = $input['active'] ?? null;
            if (!is_bool($active)) {
                dmo_package_fail('invalid_active_status');
            }
            $select = $db->prepare(
                'SELECT '.$fields.' FROM ' . $table . ' WHERE id = :id FOR UPDATE'
            );
            $select->execute(['id' => $id]);
            $current = $select->fetch();
            if (!is_array($current)) {
                $db->rollBack();
                dmo_json(404, ['ok' => false, 'error' => 'item_not_found']);
            }
            dmo_package_check_revision($db,$current,$kind==='tier',$input['expected_revision']??null);
            if ($active && !dmo_catalog_offer_valid($current)) {
                $db->rollBack();
                dmo_json(422, ['ok' => false, 'error' => 'complete_price_and_description_first']);
            }
            $db->prepare('UPDATE ' . $table . ' SET is_active = :active WHERE id = :id')
                ->execute(['active' => (int) $active, 'id' => $id]);
            dmo_admin_audit(
                $db, (int) $admin['user_id'],
                'catalog.' . $kind . ($active ? '.published' : '.unpublished')
            );
            $db->commit();
            dmo_json(200, ['ok' => true, 'id' => $id, 'is_active' => $active]);
        }

        $raw = $input['item'] ?? null;
        if (!is_array($raw) || array_is_list($raw)) {
            dmo_package_fail('invalid_item');
        }
        $item = dmo_package_normalize($raw, $kind === 'tier');
        $id = $item['id'];
        $exists = false;
        if ($id !== null) {
            $select = $db->prepare(
                'SELECT '.$fields.' FROM ' . $table . ' WHERE id = :id FOR UPDATE'
            );
            $select->execute(['id' => $id]);
            $existing = $select->fetch();
            if (!is_array($existing)) {
                $db->rollBack();
                dmo_json(404, ['ok' => false, 'error' => 'item_not_found']);
            }
            $exists = true;
            dmo_package_check_revision($db,$existing,$kind==='tier',$input['expected_revision']??null);
            // Editing a published item changes the public catalog instantly.
            // Never permit that through the ordinary draft editing switch.
            if ((bool) $existing['is_active'] && !dmo_packages_publish_enabled()) {
                $db->rollBack();
                dmo_json(503, ['ok' => false, 'error' => 'published_item_locked']);
            }
            if((bool)$existing['is_active']&&!dmo_catalog_offer_valid($item)){
                $db->rollBack();dmo_package_fail('complete_price_and_description_first');
            }
        }
        $params = [
            'slug' => $item['slug'], 'name' => $item['name'],
            'description' => $item['description'], 'currency' => $item['currency'],
            'min' => $item['estimate_min_minor'], 'max' => $item['estimate_max_minor'],
            'sort' => $item['sort_order'],
        ];
        if ($kind === 'tier') {
            $params['features'] = json_encode($item['features'], JSON_THROW_ON_ERROR);
        }
        if ($exists) {
            $params['id'] = $id;
            $pairs = [
                'slug = :slug', 'name = :name', 'description = :description',
                'currency = :currency', 'estimate_min_minor = :min',
                'estimate_max_minor = :max', 'sort_order = :sort',
            ];
            if ($kind === 'tier') $pairs[] = 'features_json = :features';
            $db->prepare('UPDATE ' . $table . ' SET ' . implode(', ', $pairs) .
                ' WHERE id = :id')->execute($params);
        } else {
            $cols = ['slug','name','description','currency',
                'estimate_min_minor','estimate_max_minor','sort_order'];
            $vals = [':slug',':name',':description',':currency',':min',':max',':sort'];
            if ($kind === 'tier') {
                $cols[] = 'features_json';
                $vals[] = ':features';
            }
            $db->prepare('INSERT INTO ' . $table . ' (' . implode(',', $cols) .
                ') VALUES (' . implode(',', $vals) . ')')->execute($params);
            $id = (int) $db->lastInsertId();
        }
        dmo_admin_audit(
            $db, (int) $admin['user_id'],
            'catalog.' . $kind . ($exists ? '.updated' : '.created')
        );
        $db->commit();
        dmo_json(200, [
            'ok' => true,
            'id' => $id,
            'note' => $exists ?
                'Item updated. Publication state unchanged.' :
                'Item created as unpublished.',
        ]);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
} catch (PDOException $error) {
    dmo_log_problem($error, 'package database');
    if ($error->getCode() === '23000') {
        dmo_json(409, ['ok' => false, 'error' => 'duplicate_slug_or_conflict']);
    }
    dmo_json(503, ['ok' => false, 'error' => 'catalog_service_unavailable']);
} catch (Throwable $error) {
    dmo_log_problem($error, 'package management');
    dmo_json(503, ['ok' => false, 'error' => 'catalog_service_unavailable']);
}
