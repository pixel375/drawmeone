<?php
declare(strict_types=1);
require_once __DIR__.'/deliveries.php';
require_once __DIR__.'/references.php';
require_once __DIR__.'/checkout.php';

/**
 * Read-only operational checks; return counts and fixed identifiers only.
 * No customer information, raw SQL, file paths or payment references.
 */
function dmo_integrity_records(PDO $db): array
{
    $schemas=['reference'=>dmo_refs_schema_ready($db),'delivery'=>dmo_delivery_schema_ready($db),
        'review'=>dmo_review_schema_ready($db),'checkout'=>dmo_checkout_schema_ready($db)];
    $checks=[
        'order_quote_mismatch'=>"SELECT COUNT(*) FROM dmo_orders o LEFT JOIN dmo_quotes q ON q.id=o.accepted_quote_id AND q.request_id=o.request_id WHERE q.id IS NULL OR q.status<>'accepted'",
        'paid_without_evidence'=>"SELECT COUNT(*) FROM dmo_orders o JOIN dmo_quotes q ON q.id=o.accepted_quote_id WHERE o.payment_status='paid' AND NOT (".dmo_fulfillment_payment_sql().")",
        'paid_order_exception_conflict'=>"SELECT COUNT(*) FROM dmo_orders o WHERE o.payment_status='paid' AND ".dmo_order_exception_sql()." IN ('payment.access_held','payment.full_refund_recorded')",
        'duplicate_verified_payment'=>"SELECT COUNT(*) FROM (SELECT order_id FROM dmo_payments WHERE status='verified' GROUP BY order_id HAVING COUNT(*)>1) duplicates",
        'cancelled_order_conflict'=>"SELECT COUNT(*) FROM dmo_orders o JOIN dmo_commission_requests r ON r.id=o.request_id WHERE o.status='cancelled' AND (r.status<>'cancelled' OR o.payment_status<>'unverified' OR EXISTS(SELECT 1 FROM dmo_payments p WHERE p.order_id=o.id AND p.status<>'rejected'))",
        'multiple_orders'=>"SELECT COUNT(*) FROM (SELECT request_id FROM dmo_orders GROUP BY request_id HAVING COUNT(*)>1) duplicates",
    ];
    $checks['portfolio_missing_variants']="SELECT COUNT(*) FROM dmo_portfolio_artworks a WHERE NOT EXISTS(SELECT 1 FROM dmo_artwork_files f WHERE f.artwork_id=a.id AND f.variant='thumb') OR NOT EXISTS(SELECT 1 FROM dmo_artwork_files f WHERE f.artwork_id=a.id AND f.variant='display')";
    $checks['portfolio_invalid_metadata']="SELECT COUNT(*) FROM dmo_artwork_files WHERE variant NOT IN ('thumb','display') OR mime_type<>'image/webp' OR byte_size<100 OR pixel_width<1 OR pixel_height<1 OR storage_key NOT LIKE CONCAT('%/',variant,'.webp')";
    $checks['portfolio_variant_folder_mismatch']="SELECT COUNT(*) FROM dmo_artwork_files t JOIN dmo_artwork_files d ON d.artwork_id=t.artwork_id AND d.variant='display' WHERE t.variant='thumb' AND SUBSTRING_INDEX(t.storage_key,'/',1)<>SUBSTRING_INDEX(d.storage_key,'/',1)";
    $checks['cancelled_request_live_quote']="SELECT COUNT(*) FROM dmo_quotes q JOIN dmo_commission_requests r ON r.id=q.request_id WHERE r.status='cancelled' AND q.status='sent'";
    $checks['cancelled_request_live_order']="SELECT COUNT(*) FROM dmo_orders o JOIN dmo_commission_requests r ON r.id=o.request_id WHERE r.status='cancelled' AND o.status<>'cancelled'";
    if($schemas['checkout']){
        $checks['cancelled_order_unclosed_invoice']="SELECT COUNT(*) FROM dmo_checkout_links l JOIN dmo_orders o ON o.id=l.order_id WHERE o.status='cancelled' AND l.status<>'revoked'";
        $checks['checkout_amount_mismatch']="SELECT COUNT(*) FROM dmo_checkout_links l JOIN dmo_orders o ON o.id=l.order_id JOIN dmo_quotes q ON q.id=o.accepted_quote_id WHERE l.currency<>q.currency OR l.amount_minor<>q.total_minor";
        $checks['checkout_multiple_unclosed']="SELECT COUNT(*) FROM (SELECT order_id FROM dmo_checkout_links WHERE status IN ('active','suspended') GROUP BY order_id HAVING COUNT(*)>1) duplicates";
        $checks['checkout_invalid_storage']="SELECT COUNT(*) FROM dmo_checkout_links WHERE status NOT IN ('active','suspended','revoked') OR (status='active' AND (url_ciphertext='' OR revoked_at IS NOT NULL)) OR (status IN ('suspended','revoked') AND url_ciphertext<>'') OR (status='suspended' AND revoked_at IS NOT NULL) OR (status='revoked' AND revoked_at IS NULL)";
        $checks['checkout_active_after_evidence']="SELECT COUNT(*) FROM dmo_checkout_links l WHERE l.status='active' AND EXISTS (SELECT 1 FROM dmo_payments p WHERE p.order_id=l.order_id AND p.status IN ('pending_verification','verified'))";
    }
    if($schemas['delivery']){
        $checks['cancelled_order_staged_artwork']="SELECT COUNT(*) FROM dmo_delivery_drafts d JOIN dmo_orders o ON o.id=d.order_id WHERE o.status='cancelled'";
        $checks['draft_order_mismatch']="SELECT COUNT(*) FROM dmo_delivery_drafts d JOIN dmo_orders o ON o.id=d.order_id WHERE d.request_id<>o.request_id";
        $checks['delivery_missing_draft']="SELECT COUNT(*) FROM dmo_deliverables f JOIN dmo_orders o ON o.id=f.order_id LEFT JOIN dmo_delivery_drafts d ON d.order_id=o.id AND d.request_id=o.request_id AND d.storage_key=f.storage_key WHERE d.id IS NULL OR f.byte_size<>d.byte_size OR f.mime_type<>'image/webp'";
        $checks['delivery_state_mismatch']="SELECT COUNT(*) FROM dmo_deliverables f JOIN dmo_orders o ON o.id=f.order_id JOIN dmo_commission_requests r ON r.id=o.request_id WHERE o.status<>'completed' OR r.status<>'completed'";
        $checks['completed_without_delivery']="SELECT COUNT(*) FROM dmo_orders o WHERE o.status='completed' AND NOT EXISTS (SELECT 1 FROM dmo_deliverables f WHERE f.order_id=o.id)";
    }
    if($schemas['review']&&$schemas['delivery']){
        $checks['cancelled_order_proof']="SELECT COUNT(*) FROM dmo_artwork_reviews v JOIN dmo_orders o ON o.id=v.order_id WHERE o.status='cancelled'";
        $checks['review_draft_mismatch']="SELECT COUNT(*) FROM dmo_artwork_reviews v LEFT JOIN dmo_delivery_drafts d ON d.id=v.draft_id WHERE v.status IN ('pending','approved') AND (d.id IS NULL OR d.order_id<>v.order_id)";
        $checks['review_pending_multiple']="SELECT COUNT(*) FROM (SELECT order_id FROM dmo_artwork_reviews WHERE status='pending' GROUP BY order_id HAVING COUNT(*)>1) duplicates";
        $checks['review_allowance_exceeded']="SELECT COUNT(*) FROM (SELECT o.id FROM dmo_orders o JOIN dmo_quotes q ON q.id=o.accepted_quote_id JOIN dmo_artwork_reviews v ON v.order_id=o.id AND v.status='revision_requested' GROUP BY o.id,q.revision_allowance HAVING COUNT(*)>q.revision_allowance) exceeded";
        $checks['delivery_approval_mismatch']="SELECT COUNT(*) FROM dmo_deliverables f JOIN dmo_artwork_reviews v ON v.id=(SELECT x.id FROM dmo_artwork_reviews x WHERE x.order_id=f.order_id ORDER BY x.round_number DESC LIMIT 1) LEFT JOIN dmo_delivery_drafts d ON d.id=v.draft_id WHERE v.status<>'approved' OR d.id IS NULL OR d.storage_key<>f.storage_key";
    }
    $counts=[];
    foreach($schemas as $name=>$ready)$counts['schema_'.$name.'_missing']=$ready?0:1;
    foreach($checks as $key=>$sql)$counts[$key]=(int)$db->query($sql)->fetchColumn();
    return $counts;
}

/** Bounded streaming scan, intended for CLI only, never for a browser request. */
function dmo_integrity_media(PDO $db,int $limit=10000): array
{
    if($limit<1||$limit>10000)throw new InvalidArgumentException('Invalid media scan limit');
    $specs=[
        'portfolio'=>['sql'=>'SELECT id,storage_key,variant,mime_type,byte_size,pixel_width,pixel_height FROM dmo_artwork_files','paths'=>static fn($row)=>[dmo_artwork_storage_file($row['storage_key'])]],
    ];
    if(dmo_refs_schema_ready($db))$specs['references']=['sql'=>'SELECT id,storage_key,mime_type,byte_size,pixel_width,pixel_height FROM dmo_request_attachments',
        'paths'=>static fn($row)=>[dmo_refs_storage_file($row['storage_key'],'display'),dmo_refs_storage_file($row['storage_key'],'thumb')]];
    if(dmo_delivery_schema_ready($db))$specs['deliveries']=['sql'=>'SELECT id,storage_key,byte_size,sha256,pixel_width,pixel_height FROM dmo_delivery_drafts',
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
                        if(!dmo_private_image_path_ok($file,$kind!=='portfolio')){
                            $bad=true;break;
                        }
                        $info=@getimagesize($file);
                        if(!is_array($info)||($info['mime']??null)!=='image/webp'){
                            $bad=true;break;
                        }
                        if($kind==='portfolio'&&!dmo_artwork_variant_valid($row)){$bad=true;break;}
                        if($kind==='references'&&!dmo_refs_variant_valid($row,str_ends_with($file,'/display.webp')?'display':'thumb')){$bad=true;break;}
                        if($kind==='deliveries'&&(filesize($file)!==(int)$row['byte_size']||(int)$info[0]!==(int)$row['pixel_width']||(int)$info[1]!==(int)$row['pixel_height'])){$bad=true;break;}
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

/**
 * CLI-only bounded authentication of stored active invoice ciphertext.
 * Verify the original order binding, HMAC and stored host, independently
 * of the current provider configuration (which may have changed).
 * No redirects, external calls, customer data or decrypted URLs in output.
 */
function dmo_integrity_checkout_crypto(PDO $db,int $limit=10000): array
{
    if($limit<1||$limit>10000)throw new InvalidArgumentException('Invalid invoice scan limit');
    if(!dmo_checkout_schema_ready($db))return ['checked'=>0,'invalid'=>0,'complete'=>false];
    $checked=0;$invalid=0;$last=0;
    while($checked<$limit){
        $size=min(100,$limit-$checked);
        $q=$db->prepare("SELECT id,order_id,url_ciphertext,url_fingerprint,provider_host FROM dmo_checkout_links WHERE status='active' AND id>:last ORDER BY id LIMIT ".$size);
        $q->execute(['last'=>$last]);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
        if(!$rows)break;
        foreach($rows as $row){
            $last=(int)$row['id'];$checked++;
            if(dmo_checkout_authenticated_url($row)===null)$invalid++;
        }
    }
    $q=$db->prepare("SELECT id FROM dmo_checkout_links WHERE status='active' AND id>:last LIMIT 1");
    $q->execute(['last'=>$last]);
    return ['checked'=>$checked,'invalid'=>$invalid,'complete'=>$q->fetchColumn()===false];
}
