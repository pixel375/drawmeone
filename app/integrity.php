<?php
declare(strict_types=1);
require_once __DIR__.'/deliveries.php';
require_once __DIR__.'/references.php';

/**
 * Read-only operational checks; return counts and fixed identifiers only.
 * No customer information, raw SQL, file paths or payment references.
 */
function dmo_integrity_records(PDO $db): array
{
    $checks=[
        'order_quote_mismatch'=>"SELECT COUNT(*) FROM dmo_orders o LEFT JOIN dmo_quotes q ON q.id=o.accepted_quote_id AND q.request_id=o.request_id WHERE q.id IS NULL OR q.status<>'accepted'",
        'paid_without_evidence'=>"SELECT COUNT(*) FROM dmo_orders o JOIN dmo_quotes q ON q.id=o.accepted_quote_id WHERE o.payment_status='paid' AND NOT (".dmo_fulfillment_payment_sql().")",
        'paid_order_exception_conflict'=>"SELECT COUNT(*) FROM dmo_orders o WHERE o.payment_status='paid' AND ".dmo_order_exception_sql()." IN ('payment.access_held','payment.full_refund_recorded')",
        'duplicate_verified_payment'=>"SELECT COUNT(*) FROM (SELECT order_id FROM dmo_payments WHERE status='verified' GROUP BY order_id HAVING COUNT(*)>1) duplicates",
        'multiple_orders'=>"SELECT COUNT(*) FROM (SELECT request_id FROM dmo_orders GROUP BY request_id HAVING COUNT(*)>1) duplicates",
    ];
    if(dmo_delivery_schema_ready($db)){
        $checks['draft_order_mismatch']="SELECT COUNT(*) FROM dmo_delivery_drafts d JOIN dmo_orders o ON o.id=d.order_id WHERE d.request_id<>o.request_id";
        $checks['delivery_missing_draft']="SELECT COUNT(*) FROM dmo_deliverables f JOIN dmo_orders o ON o.id=f.order_id LEFT JOIN dmo_delivery_drafts d ON d.order_id=o.id AND d.request_id=o.request_id AND d.storage_key=f.storage_key WHERE d.id IS NULL OR f.byte_size<>d.byte_size OR f.mime_type<>'image/webp'";
        $checks['delivery_state_mismatch']="SELECT COUNT(*) FROM dmo_deliverables f JOIN dmo_orders o ON o.id=f.order_id JOIN dmo_commission_requests r ON r.id=o.request_id WHERE o.status<>'completed' OR r.status<>'completed'";
        $checks['completed_without_delivery']="SELECT COUNT(*) FROM dmo_orders o WHERE o.status='completed' AND NOT EXISTS (SELECT 1 FROM dmo_deliverables f WHERE f.order_id=o.id)";
    }
    if(dmo_review_schema_ready($db)){
        $checks['review_draft_mismatch']="SELECT COUNT(*) FROM dmo_artwork_reviews v LEFT JOIN dmo_delivery_drafts d ON d.id=v.draft_id WHERE v.status IN ('pending','approved') AND (d.id IS NULL OR d.order_id<>v.order_id)";
        $checks['review_pending_multiple']="SELECT COUNT(*) FROM (SELECT order_id FROM dmo_artwork_reviews WHERE status='pending' GROUP BY order_id HAVING COUNT(*)>1) duplicates";
        $checks['review_allowance_exceeded']="SELECT COUNT(*) FROM (SELECT o.id FROM dmo_orders o JOIN dmo_quotes q ON q.id=o.accepted_quote_id JOIN dmo_artwork_reviews v ON v.order_id=o.id AND v.status='revision_requested' GROUP BY o.id,q.revision_allowance HAVING COUNT(*)>q.revision_allowance) exceeded";
        $checks['delivery_approval_mismatch']="SELECT COUNT(*) FROM dmo_deliverables f JOIN dmo_artwork_reviews v ON v.id=(SELECT x.id FROM dmo_artwork_reviews x WHERE x.order_id=f.order_id ORDER BY x.round_number DESC LIMIT 1) LEFT JOIN dmo_delivery_drafts d ON d.id=v.draft_id WHERE v.status<>'approved' OR d.id IS NULL OR d.storage_key<>f.storage_key";
    }
    $counts=[];
    foreach($checks as $key=>$sql)$counts[$key]=(int)$db->query($sql)->fetchColumn();
    return $counts;
}

/** Bounded streaming scan, intended for CLI only, never for a browser request. */
function dmo_integrity_media(PDO $db,int $limit=10000): array
{
    if($limit<1||$limit>10000)throw new InvalidArgumentException('Invalid media scan limit');
    $specs=[
        'portfolio'=>['sql'=>'SELECT id,storage_key FROM dmo_artwork_files','paths'=>static fn($row)=>[dmo_artwork_storage_file($row['storage_key'])]],
    ];
    if(dmo_refs_schema_ready($db))$specs['references']=['sql'=>'SELECT id,storage_key FROM dmo_request_attachments',
        'paths'=>static fn($row)=>[dmo_refs_storage_file($row['storage_key'],'display'),dmo_refs_storage_file($row['storage_key'],'thumb')]];
    if(dmo_delivery_schema_ready($db))$specs['deliveries']=['sql'=>'SELECT id,storage_key,byte_size,sha256 FROM dmo_delivery_drafts',
        'paths'=>static fn($row)=>[dmo_delivery_file($row['storage_key'])]];
    $result=[];
    foreach($specs as $kind=>$spec){
        $checked=0;$invalid=0;$last=0;
        while($checked<$limit){
            $size=min(100,$limit-$checked);
            $q=$db->prepare($spec['sql'].' WHERE id>:last ORDER BY id LIMIT '.$size);
            $q->execute(['last'=>$last]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
            if(!$rows)break;
            foreach($rows as $row){
                $last=(int)$row['id'];$checked++;
                try{
                    $bad=false;
                    foreach(($spec['paths'])($row) as $file){
                        if(is_link(dirname($file))||is_link(dirname(dirname($file)))||is_link($file)||
                            !is_file($file)||!is_readable($file)||((int)fileperms($file)&0077)!==0){
                            $bad=true;break;
                        }
                        $info=@getimagesize($file);
                        if(!is_array($info)||($info['mime']??null)!=='image/webp'){
                            $bad=true;break;
                        }
                        if($kind==='deliveries'&&(filesize($file)!==(int)$row['byte_size']||
                            !hash_equals($row['sha256'],(string)hash_file('sha256',$file)))){
                            $bad=true;break;
                        }
                    }
                    if($bad)$invalid++;
                }catch(Throwable $e){$invalid++;}
            }
        }
        $q=$db->prepare($spec['sql'].' WHERE id>:last LIMIT 1');$q->execute(['last'=>$last]);
        $result[$kind]=['checked'=>$checked,'invalid'=>$invalid,'complete'=>$q->fetchColumn()===false];
    }
    return $result;
}
