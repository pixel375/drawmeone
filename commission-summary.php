<?php
declare(strict_types=1);
require_once __DIR__.'/app/orders.php';
require_once __DIR__.'/app/public-info.php';
dmo_info_headers();
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
function dmo_summary_money(mixed $minor,string $currency): string
{
    if(!in_array($currency,['CAD','USD'],true)||!is_int($minor)||$minor<0)return 'Unavailable';
    return $currency.' '.number_format($minor/100,2,'.',',');
}
function dmo_summary_label(mixed $status): string
{
    return match($status){
        'submitted'=>'Submitted','clarification_needed'=>'Clarification needed','rejected'=>'Declined',
        'cancelled','canceled'=>'Cancelled','completed'=>'Completed','closed'=>'Closed','archived'=>'Archived',
        'accepted'=>'Accepted','sent'=>'Sent (not accepted)','declined'=>'Declined','superseded'=>'No longer available',
        'paid'=>'Recorded as reconciled','unverified'=>'Not verified','on_hold'=>'On hold','refunded'=>'Full external refund recorded',
        'awaiting_arrangement'=>'Awaiting payment arrangements','in_progress','drawing','queued'=>'Artwork in progress',
        'ready_for_delivery'=>'Ready for delivery','awaiting_feedback'=>'Awaiting feedback','revision'=>'Revision in progress',
        default=>'Under review',
    };
}
$status=200;$request=null;$quote=null;$orders=[];$pin=null;$warning=false;
try{
    $db=dmo_database();$session=dmo_customer_require($db);
    $ref=$_GET['ref']??null;
    if(!is_string($ref)||!preg_match('/^DMO-[0-9a-f]{20}$/D',$ref))$status=404;
    else{
        $db->beginTransaction();
        $q=$db->prepare("SELECT id,public_ref,subject_type,status,submitted_at,policy_version FROM dmo_commission_requests WHERE customer_id=:customer AND public_ref=:ref LIMIT 1");
        $q->execute(['customer'=>$session['user_id'],'ref'=>$ref]);$request=$q->fetch(PDO::FETCH_ASSOC);
        if(!is_array($request)){$status=404;$request=null;}
        else{
            $id=(int)$request['id'];$receipt=dmo_policy_receipt_record($db,$id);
            $pin=$receipt['state']==='recorded'&&$receipt['version']===$request['policy_version']?$receipt['sha256']:null;
            $quotes=dmo_quote_customer_rows($db,$id,$request['policy_version']);
            $orders=dmo_orders_customer_rows($db,$id);
            $accepted=array_values(array_filter($quotes,static fn($row)=>$row['status']==='accepted'));
            if(count($orders)>1||count($accepted)>1)$warning=true;
            elseif($orders){
                if(count($accepted)===1&&$accepted[0]['id']===$orders[0]['quote_id']&&$accepted[0]['currency']===$orders[0]['currency']&&$accepted[0]['total_minor']===$orders[0]['total_minor'])$quote=$accepted[0];
                else $warning=true;
            }else $quote=$accepted[0]??$quotes[0]??null;
        }
        $db->commit();
    }
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();dmo_log_problem($e,'commission summary');$status=503;$request=null;}
http_response_code($status);
function esc(mixed $s): string{return dmo_info_escape((string)$s);}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Private commission summary | Draw Me One</title><link rel="stylesheet" href="/public-info.css?v=2"></head>
<body class="private-summary"><a class="skip" href="#main">Skip to summary</a><header><a href="/commission.html">Draw Me One</a><nav><a href="/commission.html">My commissions</a></nav></header><main id="main" tabindex="-1">
<p class="eyebrow">Private customer record</p><h1>Commission summary</h1>
<?php if(!$request): ?>
<div class="notice"><h2><?= $status===503?'Summary unavailable':'Commission not found' ?></h2><p>Return to your private commission workspace and try again.</p><a href="/commission.html">My commissions</a></div>
<?php else: ?>
<p class="lead"><?= esc($request['public_ref']) ?></p>
<p class="muted">Generated <?= esc(gmdate('Y-m-d H:i:s')) ?> UTC. This is a snapshot of local records, not a tax invoice, bank receipt or provider payment confirmation. Use your browser’s Print menu to print or save a private PDF. Keep this document secure.</p>
<section class="section"><h2>Request</h2><dl><dt>Drawing type</dt><dd><?= esc(str_replace('_',' ',$request['subject_type'])) ?></dd><dt>Submitted (UTC)</dt><dd><?= esc($request['submitted_at']) ?></dd><dt>Request status</dt><dd><?= esc(dmo_summary_label($request['status'])) ?></dd><dt>Acknowledged policy</dt><dd><?php if(is_string($pin)&&preg_match('/^[a-f0-9]{64}$/D',$pin)): ?><a href="/policy.php?<?= esc(http_build_query(['version'=>$request['policy_version'],'sha256'=>$pin])) ?>"><?= esc($request['policy_version']) ?></a><?php else: ?><?= esc($request['policy_version']) ?> (receipt unavailable)<?php endif; ?></dd></dl></section>
<?php if($warning): ?><section class="section"><h2>Agreement requires review</h2><p>The order and quote records could not be matched unambiguously. Contact the artist in your private workspace before relying on any payment arrangement.</p></section>
<?php elseif($quote): ?>
<section class="section"><h2><?= $quote['status']==='accepted'?'Accepted quote':'Latest delivered quote' ?></h2>
<p>Version <?= esc($quote['version_number']) ?> · <?= esc($quote['status']==='sent'&&$quote['is_expired']?'Expired':dmo_summary_label($quote['status'])) ?></p>
<p class="price"><?= esc(dmo_summary_money($quote['total_minor'],$quote['currency'])) ?></p>
<div class="policy-body"><?= esc($quote['scope_description']) ?></div>
<dl><dt>Included revisions</dt><dd><?= esc($quote['revision_allowance']) ?></dd><dt>Sent (UTC)</dt><dd><?= esc($quote['sent_at']) ?></dd><?php if($quote['accepted_at']): ?><dt>Accepted (UTC)</dt><dd><?= esc($quote['accepted_at']) ?></dd><?php endif; ?><?php if($quote['validity_ends_at']): ?><dt>Offer valid until (UTC)</dt><dd><?= esc($quote['validity_ends_at']) ?></dd><?php endif; ?></dl>
<?php if($quote['lines']): ?><h3>Itemized scope</h3><ul><?php foreach($quote['lines'] as $line): ?><li><?= esc($line['description']) ?> — <?= esc(dmo_summary_money($line['amount_minor'],$quote['currency'])) ?></li><?php endforeach; ?></ul><?php endif; ?>
</section>
<?php else: ?><section class="section"><h2>Quote</h2><p>No quote has been released to you yet.</p></section><?php endif; ?>
<?php if(!$warning&&$orders): $order=$orders[0]; ?><section class="section"><h2>Confirmed order</h2><dl><dt>Order status</dt><dd><?= esc(dmo_summary_label($order['status'])) ?></dd><dt>Recorded payment status</dt><dd><?= esc(dmo_summary_label($order['payment_status'])) ?></dd><dt>Agreed amount</dt><dd><?= esc(dmo_summary_money($order['total_minor'],$order['currency'])) ?></dd><dt>Agreed deadline</dt><dd><?= esc($order['agreed_deadline']??'Not agreed') ?></dd></dl></section>
<?php elseif(!$warning): ?><section class="section"><h2>Order</h2><p>No confirmed order is recorded. A quote alone is not an order or payment.</p></section><?php endif; ?>
<p class="muted">Private messages, reference images, delivery links and payment credentials are deliberately excluded. For current status, return to your commission workspace.</p>
<?php endif; ?>
</main><footer>Draw Me One · Private customer record</footer></body></html>
