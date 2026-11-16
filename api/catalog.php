<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

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

    dmo_json(200, [
        'ok' => true,
        'requests_open' => false,
        'currency_pricing_approved' => false,
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
