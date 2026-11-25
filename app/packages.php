<?php
declare(strict_types=1);
require_once __DIR__ . '/admin.php';

/**
 * All package and add-on changes are controlled by the artist.
 * This phase intentionally has no deletion or automatic publication.
 */
function dmo_packages_writes_enabled(): bool
{
    return dmo_config('DMO_PACKAGE_WRITES_ENABLED') === '1';
}

function dmo_packages_publish_enabled(): bool
{
    return dmo_packages_writes_enabled()
        && dmo_config('DMO_PACKAGE_PUBLISH_ENABLED') === '1'
        && dmo_config('DMO_PRICING_APPROVED') === '1';
}

function dmo_package_fail(string $reason): never
{
    dmo_json(422, ['ok' => false, 'error' => $reason]);
}

function dmo_package_id(mixed $id, bool $allowNew): ?int
{
    if ($allowNew && $id === null) {
        return null;
    }
    if (!is_int($id) || $id < 1 || $id > PHP_INT_MAX) {
        dmo_package_fail('invalid_item_id');
    }
    return $id;
}

function dmo_package_text(mixed $value, int $limit, bool $required = false): string
{
    if (!is_string($value) || strlen($value) > $limit ||
        preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
        dmo_package_fail('invalid_text');
    }
    $text = trim($value);
    if ($required && (strlen($text) < 2 || strlen($text) > $limit)) {
        dmo_package_fail('invalid_text');
    }
    return $text;
}

function dmo_package_price(mixed $min, mixed $max): array
{
    if ($min === null && $max === null) {
        return [null, null];
    }
    // Store minor units (e.g. CAD cents), NOT floats.
    if (!is_int($min) || !is_int($max) || $min < 0 || $max < $min ||
        $max > 10000000) {
        dmo_package_fail('invalid_estimate');
    }
    return [$min, $max];
}

function dmo_package_normalize(array $input, bool $isTier): array
{
    // Unknown keys fail closed instead of silently accepting extra fields.
    $permitted = ['id','slug','name','description','currency',
        'estimate_min_minor','estimate_max_minor','sort_order'];
    if ($isTier) {
        $permitted[] = 'features';
    }
    foreach ($input as $key => $value) {
        if (!in_array($key, $permitted, true)) {
            dmo_package_fail('unrecognized_field');
        }
    }

    $id = dmo_package_id($input['id'] ?? null, true);
    $slug = $input['slug'] ?? '';
    if (!is_string($slug) ||
        !preg_match('/^[a-z0-9][a-z0-9-]{1,79}$/D', $slug)) {
        dmo_package_fail('invalid_slug');
    }
    $name = dmo_package_text($input['name'] ?? null, 120, true);
    $description = dmo_package_text($input['description'] ?? '', 2500);
    $currency = $input['currency'] ?? null;
    if (!is_string($currency) || !in_array($currency, ['CAD','USD'], true)) {
        dmo_package_fail('unsupported_currency');
    }
    [$min,$max] = dmo_package_price(
        $input['estimate_min_minor'] ?? null,
        $input['estimate_max_minor'] ?? null
    );
    $sort = $input['sort_order'] ?? 0;
    if (!is_int($sort) || $sort < -10000 || $sort > 10000) {
        dmo_package_fail('invalid_sort_order');
    }

    $features = [];
    if ($isTier) {
        $raw = $input['features'] ?? [];
        if (!is_array($raw) || !array_is_list($raw) || count($raw) > 20) {
            dmo_package_fail('invalid_features');
        }
        foreach ($raw as $feature) {
            $line = dmo_package_text($feature, 180, true);
            $features[] = $line;
        }
    }
    return [
        'id' => $id,
        'slug' => $slug,
        'name' => $name,
        'description' => $description,
        'currency' => $currency,
        'estimate_min_minor' => $min,
        'estimate_max_minor' => $max,
        'sort_order' => $sort,
        'features' => $features,
    ];
}

function dmo_packages_list(PDO $db): array
{
    $tiers = $db->query(
        'SELECT id, slug, name, description, currency, estimate_min_minor, ' .
        'estimate_max_minor, features_json, sort_order, is_active ' .
        'FROM dmo_commission_tiers ORDER BY sort_order, id LIMIT 100'
    )->fetchAll();
    foreach ($tiers as &$tier) {
        $tier['revision']=dmo_package_revision($tier,true);
        $values = json_decode((string) ($tier['features_json'] ?? '[]'), true);
        $tier['features'] = is_array($values) && array_is_list($values) ? $values : [];
        unset($tier['features_json']);
        $tier['id'] = (int) $tier['id'];
        $tier['is_active'] = (bool) $tier['is_active'];
        $tier['sort_order'] = (int) $tier['sort_order'];
        foreach (['estimate_min_minor', 'estimate_max_minor'] as $field) {
            $tier[$field] = $tier[$field] === null ? null : (int) $tier[$field];
        }
    }
    unset($tier);
    $addons = $db->query(
        'SELECT id, slug, name, description, currency, estimate_min_minor, ' .
        'estimate_max_minor, sort_order, is_active FROM dmo_addons ' .
        'ORDER BY sort_order, id LIMIT 101'
    )->fetchAll();
    $addonIds=array_map('intval',array_column($addons,'id'));sort($addonIds,SORT_NUMERIC);
    $addons=array_slice($addons,0,100);
    foreach ($addons as &$addon) {
        $addon['revision']=dmo_package_revision($addon,false);
        $addon['id'] = (int) $addon['id'];
        $addon['is_active'] = (bool) $addon['is_active'];
        $addon['sort_order'] = (int) $addon['sort_order'];
        foreach (['estimate_min_minor', 'estimate_max_minor'] as $field) {
            $addon[$field] = $addon[$field] === null ? null : (int) $addon[$field];
        }
    }
    unset($addon);
    foreach($tiers as &$tier)$tier['compatibility']=dmo_package_compatibility($db,$tier['id'],$addonIds);
    unset($tier);
    return ['tiers' => $tiers, 'addons' => $addons];
}

/** Content-based optimistic revision, identical for list and locked row. */
function dmo_package_revision(array $row,bool $tier): string
{
    $keys=['id','slug','name','description','currency','estimate_min_minor','estimate_max_minor','sort_order','is_active'];
    if($tier)$keys[]='features_json';
    $snapshot=[];
    foreach($keys as $key)$snapshot[$key]=isset($row[$key])?(string)$row[$key]:null;
    return hash('sha256',json_encode($snapshot,JSON_THROW_ON_ERROR));
}
function dmo_package_check_revision(PDO $db,array $row,bool $tier,mixed $expected): void
{
    if(!is_string($expected)||!preg_match('/^[a-f0-9]{64}$/D',$expected)||
        !hash_equals(dmo_package_revision($row,$tier),$expected)){
        $db->rollBack();
        dmo_json(409,['ok'=>false,'error'=>'stale_catalog_reload_required']);
    }
}

/** An empty mapping means unrestricted; a mapping with all false means none.
 * Include the complete add-on ID inventory in the revision so a stale tab
 * cannot accidentally omit newly created add-ons when saving restrictions.
 */
function dmo_package_compatibility(PDO $db,int $tierId,array $addonIds,bool $lock=false): array
{
    $q=$db->prepare('SELECT addon_id,is_available FROM dmo_tier_addons WHERE tier_id=:tier ORDER BY addon_id LIMIT 101'.($lock?' FOR UPDATE':''));
    $q->execute(['tier'=>$tierId]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $pairs=[];$allowed=[];
    foreach($rows as $row){
        $id=(int)$row['addon_id'];$yes=(int)$row['is_available']===1;
        $pairs[]=[$id,$yes];if($yes)$allowed[]=$id;
    }
    $editable=count($addonIds)<=100&&count($rows)<=100;
    return ['mode'=>$rows?'selected':'all','allowed_addon_ids'=>$allowed,'editable'=>$editable,
        'revision'=>$editable?hash('sha256',json_encode([$addonIds,$pairs],JSON_THROW_ON_ERROR)):null];
}
function dmo_package_compatibility_locked(PDO $db,int $tierId): array
{
    $ids=array_map('intval',$db->query('SELECT id FROM dmo_addons ORDER BY id LIMIT 101 FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN));
    return [$ids,dmo_package_compatibility($db,$tierId,$ids,true)];
}
function dmo_package_check_compatibility(PDO $db,array $state,mixed $revision): void
{
    if(!$state['editable']||!is_string($revision)||!is_string($state['revision'])||!hash_equals($state['revision'],$revision)){
        $db->rollBack();dmo_json(409,['ok'=>false,'error'=>'stale_catalog_reload_required']);
    }
}
