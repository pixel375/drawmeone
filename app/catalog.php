<?php
declare(strict_types=1);
require_once __DIR__.'/requests.php';

function dmo_catalog_approved(): bool
{
    return dmo_config('DMO_PRICING_APPROVED')==='1';
}
/** Shared minimum standard for visible and selectable offers. */
function dmo_catalog_offer_valid(array $item): bool
{
    if(!in_array($item['currency']??null,['CAD','USD'],true)||
        trim((string)($item['name']??''))===''||trim((string)($item['description']??''))==='')return false;
    $min=$item['estimate_min_minor']??null;$max=$item['estimate_max_minor']??null;
    foreach([$min,$max] as $value){
        if(!is_int($value)&&(!is_string($value)||!preg_match('/^[0-9]{1,8}$/D',$value)))return false;
    }
    return (int)$min>=0&&(int)$max>=(int)$min&&(int)$max<=10000000;
}
function dmo_catalog_public(PDO $db): array
{
    if(!dmo_catalog_approved())return ['tiers'=>[],'addons'=>[]];
    $offers=[];
    foreach(['tiers'=>'dmo_commission_tiers','addons'=>'dmo_addons'] as $kind=>$table){
        $isTier=$kind==='tiers';$limit=$isTier?50:100;
        $fields='id,slug,name,description,currency,estimate_min_minor,estimate_max_minor'.($isTier?',features_json':'');
        $rows=$db->query("SELECT ".$fields." FROM ".$table." WHERE is_active=1 ORDER BY sort_order,id LIMIT ".$limit)->fetchAll(PDO::FETCH_ASSOC);
        $offers[$kind]=[];
        foreach($rows as $row){
            if(!dmo_catalog_offer_valid($row))continue;
            $item=['id'=>(int)$row['id'],'slug'=>(string)$row['slug'],'name'=>(string)$row['name'],
                'description'=>(string)$row['description'],'currency'=>(string)$row['currency'],
                'estimate_min_minor'=>(int)$row['estimate_min_minor'],'estimate_max_minor'=>(int)$row['estimate_max_minor']];
            if($isTier){
                $features=json_decode((string)$row['features_json'],true);
                $item['features']=is_array($features)&&array_is_list($features)?array_slice(array_values(array_filter($features,'is_string')),0,20):[];
            }
            $offers[$kind][]=$item;
        }
    }
    // Determine restriction presence independently of available matches,
    // including explicitly denied or unpublished add-ons. No arbitrary
    // global mapping LIMIT can accidentally turn a restricted tier into an unrestricted one.
    $tierIds=array_column($offers['tiers'],'id');$addonIds=array_column($offers['addons'],'id');
    $restricted=[];$matches=[];
    if($tierIds){
        $list=implode(',',array_map('intval',$tierIds));
        foreach($db->query("SELECT DISTINCT tier_id FROM dmo_tier_addons WHERE tier_id IN ($list)")->fetchAll(PDO::FETCH_COLUMN) as $id)$restricted[(int)$id]=true;
        if($addonIds){
            $addons=implode(',',array_map('intval',$addonIds));
            $links=$db->query("SELECT tier_id,addon_id FROM dmo_tier_addons WHERE is_available=1 AND tier_id IN ($list) AND addon_id IN ($addons)");
            foreach($links->fetchAll(PDO::FETCH_ASSOC) as $link)$matches[(int)$link['tier_id']][]=(int)$link['addon_id'];
        }
    }
    foreach($offers['tiers'] as &$tier)$tier['available_addon_ids']=isset($restricted[$tier['id']])?($matches[$tier['id']]??[]):null;
    unset($tier);
    return $offers;
}
