<?php
declare(strict_types=1);
require_once __DIR__.'/payments.php';

/** Reporting is deliberately separate from payment or provider actions.
 * Classify inconsistent evidence separately; do not double-count it as received.
 */
function dmo_finance_ledger_sql(): string
{
    $exception=dmo_order_exception_sql();
    return "SELECT p.id,p.order_id,o.request_id,r.public_ref,p.method,p.currency,p.amount_minor,p.status,
      p.created_at,p.verified_at,
      CASE
      WHEN p.currency NOT IN ('CAD','USD') OR p.amount_minor<1 THEN 'review'
      WHEN p.status='rejected' THEN 'rejected'
      WHEN p.status='pending_verification' AND o.payment_status='unverified' AND o.status='awaiting_arrangement'
        AND q.status='accepted' AND q.request_id=o.request_id AND p.currency=q.currency AND p.amount_minor=q.total_minor
        AND p.provider_reference LIKE 'manual-hmac-v1:%' AND COALESCE($exception,'') NOT IN ('payment.access_held','payment.full_refund_recorded')
        AND (SELECT COUNT(*) FROM dmo_payments z WHERE z.order_id=o.id AND z.status IN ('pending_verification','verified'))=1 THEN 'pending'
      WHEN p.status='verified' AND p.verified_at IS NOT NULL AND p.verified_by IS NOT NULL
        AND q.status='accepted' AND q.request_id=o.request_id AND p.currency=q.currency AND p.amount_minor=q.total_minor
        AND p.provider_reference LIKE 'manual-hmac-v1:%'
        AND (SELECT COUNT(*) FROM dmo_payments z WHERE z.order_id=o.id AND z.status IN ('pending_verification','verified'))=1
        THEN CASE
          WHEN $exception='payment.full_refund_recorded' AND o.payment_status='unverified' THEN 'refunded'
          WHEN $exception='payment.access_held' AND o.payment_status='unverified' THEN 'held'
          WHEN o.payment_status='paid' AND COALESCE($exception,'') NOT IN ('payment.access_held','payment.full_refund_recorded') THEN 'reconciled'
          ELSE 'review' END
      ELSE 'review' END AS record_state
      FROM dmo_payments p JOIN dmo_orders o ON o.id=p.order_id
      JOIN dmo_commission_requests r ON r.id=o.request_id
      LEFT JOIN dmo_quotes q ON q.id=o.accepted_quote_id";
}
function dmo_finance_date(mixed $value): ?string
{
    if($value===null||$value==='')return null;
    if(!is_string($value)||!preg_match('/^(20[0-9]{2})-([0-9]{2})-([0-9]{2})$/D',$value,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))
        dmo_json(422,['ok'=>false,'error'=>'invalid_finance_date']);
    return $value;
}
function dmo_finance_report(PDO $db,string $state,string $currency,?int $before,?string $from=null,?string $to=null): array
{
    $ledger=dmo_finance_ledger_sql();
    $dates=[];$dateParams=[];
    if($from!==null){$dates[]='created_at>=:date_from';$dateParams['date_from']=$from.' 00:00:00';}
    if($to!==null){$dates[]='created_at<DATE_ADD(:date_to,INTERVAL 1 DAY)';$dateParams['date_to']=$to;}
    $dateWhere=$dates?' WHERE '.implode(' AND ',$dates):'';
    $summary=$db->prepare("SELECT currency,record_state,COUNT(*) AS record_count,SUM(amount_minor) AS amount_minor FROM ($ledger) ledger".$dateWhere." GROUP BY currency,record_state ORDER BY currency,record_state");
    $summary->execute($dateParams);$groups=$summary->fetchAll(PDO::FETCH_ASSOC);
    foreach($groups as &$g){$g['record_count']=(int)$g['record_count'];$g['amount_minor']=(int)$g['amount_minor'];}unset($g);
    $matching=0;
    foreach($groups as $group){
        if(($state==='all'||$group['record_state']===$state)&&($currency==='all'||$group['currency']===$currency))$matching+=$group['record_count'];
    }
    $where=$dates;$params=$dateParams;
    if($state!=='all'){$where[]='record_state=:state';$params['state']=$state;}
    if($currency!=='all'){$where[]='currency=:currency';$params['currency']=$currency;}
    if($before!==null){$where[]='id<:before';$params['before']=$before;}
    $q=$db->prepare("SELECT * FROM ($ledger) ledger".($where?' WHERE '.implode(' AND ',$where):'').' ORDER BY id DESC LIMIT 26');
    $q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $more=count($rows)>25;if($more)array_pop($rows);
    foreach($rows as &$row)foreach(['id','order_id','request_id','amount_minor'] as $key)$row[$key]=(int)$row[$key];unset($row);
    return ['ok'=>true,'readonly'=>true,'scope'=>'local_records_only','as_of'=>gmdate('c'),
        'date_basis'=>'record_created_utc','date_from'=>$from,'date_to'=>$to,'matching_count'=>$matching,'summary'=>$groups,'records'=>$rows,
        'next_before'=>$more?$rows[count($rows)-1]['id']:null];
}
