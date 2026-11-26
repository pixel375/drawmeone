<?php
declare(strict_types=1);
require_once __DIR__.'/app/public-info.php';
require_once __DIR__.'/app/catalog.php';
dmo_info_headers();
$catalog=['tiers'=>[],'addons'=>[]];$intake=['requests_open'=>false,'intake_reason'=>'not_available'];$failed=false;
try{
    $db=dmo_database();$catalog=dmo_catalog_public($db);$intake=dmo_intake_public_state($db);
}catch(Throwable $error){dmo_log_problem($error,'public services');http_response_code(503);$failed=true;}
function dmo_service_price(array $item): string
{
    $amount=static fn(int $minor):string=>number_format($minor/100,2,'.',',');
    $min=$amount($item['estimate_min_minor']);$max=$amount($item['estimate_max_minor']);
    return $item['currency'].' '.$min.($min===$max?'':' – '.$max);
}
function dmo_service_offer(array $item,bool $choose=false,?array $extras=null): void
{
    echo '<article class="offer"><h3>'.dmo_info_escape($item['name']).'</h3><p class="price">'.dmo_info_escape(dmo_service_price($item)).'</p><p>'.nl2br(dmo_info_escape($item['description']),false).'</p>';
    if(!empty($item['features'])){
        echo '<ul>';foreach($item['features'] as $feature)echo '<li>'.dmo_info_escape($feature).'</li>';echo '</ul>';
    }
    if($extras!==null){
        $allowed=$item['available_addon_ids']??null;
        $compatible=array_values(array_filter($extras,static fn(array $extra):bool=>$allowed===null||in_array($extra['id'],$allowed,true)));
        echo '<div class="offer-compatibility"><strong>Available published extras</strong>';
        if(!$compatible)echo '<p class="muted">No published extras available for this package.</p>';
        else{
            echo '<ul>';
            foreach($compatible as $extra)echo '<li>'.dmo_info_escape($extra['name']).' · '.dmo_info_escape(dmo_service_price($extra)).'</li>';
            echo '</ul>';
            if(array_filter($compatible,static fn(array $extra):bool=>$extra['currency']!==$item['currency']))echo '<p class="muted">Some extras use a different currency; an individual quote is required.</p>';
        }
        echo '</div>';
    }
    if($choose)echo '<p class="actions"><a href="/commission.html?package='.dmo_info_escape(rawurlencode($item['slug'])).'">Choose this package</a></p>';
    echo '</article>';
}
dmo_info_head('Commission services','Explore published drawing services and learn how custom commissions work.','services');
?>
<p class="eyebrow">Original art, made for you</p>
<h1>Commission services</h1>
<p class="lead">Share your idea with the artist, discuss the scope, and agree on a custom quote before placing an order.</p>
<div class="notice">
<?php if($failed): ?>
<h2>Availability could not be checked</h2><p>Services are temporarily unavailable. Please try again later or email a general question.</p>
<?php elseif($intake['requests_open']): ?>
<h2>Commission requests open</h2><p>Send a private request for artist review. Submitting a request does not reserve a slot, confirm an order or authorize a payment.</p>
<?php elseif($intake['intake_reason']==='at_capacity'): ?>
<h2>Currently at capacity</h2><p>New requests are temporarily unavailable. Existing customers can still view their commissions when account access is available.</p>
<?php elseif($intake['intake_reason']==='paused'): ?>
<h2>Requests temporarily paused</h2><p>General studio enquiries are welcome by email.</p>
<?php else: ?>
<h2>Commission requests not open</h2><p>General questions are welcome while the studio prepares its services.</p>
<?php endif; ?>
<div class="actions"><a class="primary" href="/commission.html"><?= $intake['requests_open']&&!$failed?'Start a commission request':'Commission workspace' ?></a><a href="mailto:support@drawmeone.com?subject=Draw%20Me%20One%20inquiry">Email the studio</a></div>
</div>
<section class="section" aria-labelledby="packages"><h2 id="packages">Drawing packages</h2>
<?php if($failed): ?><p>Prices could not be loaded.</p>
<?php elseif(!$catalog['tiers']): ?><p>No package estimates are published at present. Prices are agreed individually in an artist-issued quote when commissions are available.</p>
<?php else: ?><p class="muted">Published estimates, not final prices. Currency is shown explicitly; the accepted quote determines the actual amount and scope.</p><div class="cards"><?php foreach($catalog['tiers'] as $item)dmo_service_offer($item,$intake['requests_open']===true,$catalog['addons']); ?></div>
<?php endif; ?>
</section>
<?php if(!$failed&&$catalog['addons']): ?>
<section class="section" aria-labelledby="extras"><h2 id="extras">Optional extras</h2><p class="muted">Available extras depend on the selected drawing package and artist review.</p><div class="cards"><?php foreach($catalog['addons'] as $item)dmo_service_offer($item); ?></div></section>
<?php endif; ?>
<section class="section" aria-labelledby="process"><h2 id="process">How it works</h2><div class="cards"><article class="offer"><h3>1. Share your idea</h3><p>Send a private description when intake is open. The artist reviews your request.</p></article><article class="offer"><h3>2. Agree on a quote</h3><p>Review the proposed scope, price, currency and timing. Acceptance is separate from order confirmation.</p></article><article class="offer"><h3>3. Confirm the order</h3><p>Any payment and delivery arrangements follow the agreed quote. Keep track of progress in your commission workspace.</p></article></div></section>
<section class="section" aria-labelledby="faq"><h2 id="faq">Frequently asked questions</h2>
<details><summary>Is a request a confirmed order?</summary><p>No. A request is sent for review; the artist must separately agree on a quote and confirm the order.</p></details>
<details><summary>Are the listed prices final?</summary><p>No. Published package prices are estimates. The exact amount, currency and scope are agreed in the quote.</p></details>
<details><summary>Can I ask for a drawing that is not listed?</summary><p>Yes, when requests are open, select a custom idea and describe what you would like. The artist reviews the scope, available extras and price individually.</p></details>
<details><summary>Can I request a specific deadline?</summary><p>You can indicate a preferred date in a request. The date is not guaranteed until separately agreed.</p></details>
<details><summary>Can I send reference images?</summary><p>The private workspace supports reference uploads when the service is enabled. Reference images are not published in the gallery.</p></details>
<details><summary>Where can I see the terms and privacy policy?</summary><p>Read the <a href="/policy.php">approved customer policies</a> when published. The request form requires acknowledgement of the applicable version.</p></details>
</section>
<?php dmo_info_footer(); ?>
