<?php
declare(strict_types=1);
require_once __DIR__.'/app/public-info.php';
require_once __DIR__.'/app/policies.php';
dmo_info_headers();
$doc=dmo_policy_public_reference($_GET['version']??null,$_GET['sha256']??null);
if($doc===null)http_response_code(404);
dmo_info_head('Customer policies','Read the published commission terms and privacy policy.');
?>
<p class="eyebrow">Customer information</p><h1>Customer policies</h1>
<?php if($doc===null): ?>
<div class="notice"><h2>Approved policies unavailable</h2><p>The requested approved policy version is not available. Draft documents are not published here.</p><p><a href="/policy.php">Check the current policies</a> or <a href="mailto:support@drawmeone.com">email the studio</a>.</p></div>
<?php else: ?>
<p class="policy-meta">Version <?= dmo_info_escape($doc['version']) ?> · Effective <?= dmo_info_escape($doc['effective_date']) ?> (UTC date)</p>
<nav aria-label="Policy sections"><a href="#terms">Commission terms</a><a href="#privacy">Privacy policy</a><a href="/policy.php?<?= dmo_info_escape(http_build_query(['version'=>$doc['version'],'sha256'=>$doc['sha256']])) ?>">Permanent version link</a></nav>
<section class="section" aria-labelledby="terms"><h2 id="terms">Commission terms</h2><div class="policy-body"><?= dmo_info_escape($doc['terms']) ?></div></section>
<section class="section" aria-labelledby="privacy"><h2 id="privacy">Privacy policy</h2><div class="policy-body"><?= dmo_info_escape($doc['privacy']) ?></div></section>
<?php endif; ?>
<?php dmo_info_footer(); ?>
