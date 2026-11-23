<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/requests.php';

dmo_reject_non_get();

try {
    $database = dmo_database();

    // No seed data and no presumed prices. Only explicitly activated services
    // will be visible to public visitors when an artist publishes them.
    $tiers = $database->query(
        'SELECT id, slug, name, description, currency, estimate_min_minor, ' .
        'estimate_max_minor, features_json FROM dmo_commission_tiers ' .
        'WHERE is_active = 1 ORDER BY sort_order, id LIMIT 50'
    )->fetchAll();

    $publicTiers = [];
    foreach ($tiers as $tier) {
        $features = [];
        if (is_string($tier['features_json']) && $tier['features_json'] !== '') {
            $decoded = json_decode($tier['features_json'], true);
            if (is_array($decoded) && array_is_list($decoded)) {
                $features = array_values(array_filter($decoded, 'is_string'));
                $features = array_slice($features, 0, 30);
            }
        }
        $publicTiers[] = [
            'id' => (int) $tier['id'],
            'slug' => $tier['slug'],
            'name' => $tier['name'],
            'description' => $tier['description'],
            'currency' => $tier['currency'],
            'estimate_min_minor' => $tier['estimate_min_minor'] === null
                ? null : (int) $tier['estimate_min_minor'],
            'estimate_max_minor' => $tier['estimate_max_minor'] === null
                ? null : (int) $tier['estimate_max_minor'],
            'features' => $features,
        ];
    }

    $addons = $database->query(
        'SELECT id, slug, name, description, currency, estimate_min_minor, ' .
        'estimate_max_minor FROM dmo_addons WHERE is_active = 1 ' .
        'ORDER BY sort_order, id LIMIT 100'
    )->fetchAll();

    $publicAddons = [];
    foreach ($addons as $addon) {
        $publicAddons[] = [
            'id' => (int) $addon['id'],
            'slug' => $addon['slug'],
            'name' => $addon['name'],
            'description' => $addon['description'],
            'currency' => $addon['currency'],
            'estimate_min_minor' => $addon['estimate_min_minor'] === null
                ? null : (int) $addon['estimate_min_minor'],
            'estimate_max_minor' => $addon['estimate_max_minor'] === null
                ? null : (int) $addon['estimate_max_minor'],
        ];
    }


    // When the artist configures tier/add-on compatibility, expose only
    // active, approved matches. Null means no explicit restrictions.
    $mapping = [];
    $linked = $database->query(
        'SELECT tier_id, addon_id, is_available FROM dmo_tier_addons LIMIT 2000'
    )->fetchAll();
    $publicAddonIds = array_fill_keys(
        array_map(static fn(array $addon): int => $addon['id'], $publicAddons), true
    );
    foreach ($linked as $link) {
        $tier = (int) $link['tier_id'];
        $addon = (int) $link['addon_id'];
        $mapping[$tier] ??= [];
        if ((int) $link['is_available'] === 1 && isset($publicAddonIds[$addon])) {
            $mapping[$tier][] = $addon;
        }
    }
    foreach ($publicTiers as &$tier) {
        $tier['available_addon_ids'] = $mapping[$tier['id']] ?? null;
    }
    unset($tier);

    dmo_json(200, [
        'ok' => true,
        'requests_open' => dmo_requests_open(),
        'currency_pricing_approved' => dmo_config('DMO_PRICING_APPROVED') === '1',
        'tiers' => $publicTiers,
        'addons' => $publicAddons,
    ]);
} catch (Throwable $error) {
    dmo_log_problem($error, 'catalog');
    dmo_json(503, [
        'ok' => false,
        'error' => 'service_unavailable',
        'requests_open' => false,
    ]);
}
