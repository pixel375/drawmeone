<?php
declare(strict_types=1);
require_once __DIR__.'/payments.php';

function dmo_checkout_schema_ready(PDO $db): bool {
    return $db->query("SELECT version FROM dmo_schema_migrations WHERE version='006_checkout_links'")->fetchColumn()==='006_checkout_links';
}
function dmo_checkout_config(): ?array {
    $host=dmo_config('DMO_CHECKOUT_HOST');$prefix=dmo_config('DMO_CHECKOUT_PATH_PREFIX');
    if(!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D',$host)||
        strlen($host)>253||!preg_match('~^/(?:[A-Za-z0-9_-]+/)+$~D',$prefix))return null;
    return ['host'=>$host,'prefix'=>$prefix];
}
function dmo_checkout_enabled(PDO $db): bool {
    return dmo_manual_payments_enabled()&&dmo_checkout_schema_ready($db)&&
        dmo_config('DMO_CHECKOUT_LINKS_ENABLED')==='1'&&dmo_config('DMO_CHECKOUT_TERMS_APPROVED')==='1'&&
        dmo_checkout_config()!==null&&strlen(dmo_config('DMO_APP_KEY'))>=32&&function_exists('openssl_encrypt');
}
/** Deliberately accepts only a restricted, canonical HTTPS URL grammar.
 * No credentials, query strings, ports, fragments, encoded characters or redirects under our control.
 */
function dmo_checkout_url(mixed $url): bool {
    $config=dmo_checkout_config();
    if($config===null||!is_string($url)||strlen($url)>1000)return false;
    $start='https://'.$config['host'].$config['prefix'];
    return str_starts_with($url,$start)&&preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D',substr($url,strlen($start)))===1;
}
function dmo_checkout_key(): string {
    return hash_hkdf('sha256',dmo_config('DMO_APP_KEY'),32,'drawmeone-checkout-url-v1');
}
function dmo_checkout_seal(string $url,int $order): string {
    $iv=random_bytes(12);$tag='';
    $data=openssl_encrypt($url,'aes-256-gcm',dmo_checkout_key(),OPENSSL_RAW_DATA,$iv,$tag,(string)$order,16);
    if(!is_string($data)||strlen($tag)!==16)throw new RuntimeException('Checkout encryption unavailable');
    return base64_encode($iv.$tag.$data);
}
function dmo_checkout_unseal(string $cipher,int $order): ?string {
    $data=base64_decode($cipher,true);
    if(!is_string($data)||strlen($data)<29)return null;
    $url=openssl_decrypt(substr($data,28),'aes-256-gcm',dmo_checkout_key(),OPENSSL_RAW_DATA,substr($data,0,12),substr($data,12,16),(string)$order);
    return is_string($url)&&dmo_checkout_url($url)?$url:null;
}
function dmo_checkout_eligible(PDO $db,int $request,int $order): ?array {
    $q=$db->prepare("SELECT o.id,q.currency,q.total_minor,r.policy_version,r.status AS request_status FROM dmo_orders o
        JOIN dmo_quotes q ON q.id=o.accepted_quote_id AND q.request_id=o.request_id
        JOIN dmo_commission_requests r ON r.id=o.request_id JOIN dmo_users u ON u.id=r.customer_id
        WHERE o.id=:order AND r.id=:request AND q.status='accepted' AND q.total_minor>0
        AND o.status='awaiting_arrangement' AND o.payment_status='unverified'
        AND u.role='customer' AND u.account_status='active' AND u.email_verified_at IS NOT NULL
        AND COALESCE(".dmo_order_exception_sql().",'') NOT IN ('payment.access_held','payment.full_refund_recorded')
        AND NOT EXISTS(SELECT 1 FROM dmo_payments p WHERE p.order_id=o.id AND p.status IN ('pending_verification','verified'))");
    $q->execute(['order'=>$order,'request'=>$request]);$r=$q->fetch(PDO::FETCH_ASSOC);
    return is_array($r)&&dmo_messages_request_open($r['request_status'])&&dmo_policy_request_eligible($db,$request,$r['policy_version'])?$r:null;
}
function dmo_checkout_latest(PDO $db,int $order): ?array {
    $q=$db->prepare("SELECT id,order_id,provider_host,currency,amount_minor,status,expires_at,created_at,(expires_at>UTC_TIMESTAMP()) AS fresh FROM dmo_checkout_links WHERE order_id=:order ORDER BY id DESC LIMIT 1");
    $q->execute(['order'=>$order]);$r=$q->fetch(PDO::FETCH_ASSOC);
    if(!is_array($r))return null;
    foreach(['id','order_id','amount_minor'] as $k)$r[$k]=(int)$r[$k];
    $r['fresh']=(bool)$r['fresh'];return $r;
}
function dmo_checkout_rows(PDO $db,int $request,bool $admin=false): array {
    if(!dmo_checkout_schema_ready($db)||(!$admin&&!dmo_checkout_enabled($db)))return [];
    $q=$db->prepare('SELECT id FROM dmo_orders WHERE request_id=:id ORDER BY id LIMIT 3');$q->execute(['id'=>$request]);$result=[];
    foreach($q->fetchAll(PDO::FETCH_COLUMN) as $id){
        $id=(int)$id;$latest=dmo_checkout_latest($db,$id);
        $eligible=dmo_checkout_enabled($db)?dmo_checkout_eligible($db,$request,$id):null;
        if($admin){
            $result[]=['order_id'=>$id,'latest'=>$latest,'can_issue'=>$eligible!==null&&($latest===null||$latest['status']==='revoked'),
                'can_revoke'=>$latest!==null&&$latest['status']==='active','provider_host'=>dmo_checkout_config()['host']??null];
        }elseif($eligible!==null&&$latest!==null&&$latest['status']==='active'&&$latest['fresh']&&
            $latest['provider_host']===(dmo_checkout_config()['host']??null)&&$latest['currency']===$eligible['currency']&&$latest['amount_minor']===(int)$eligible['total_minor']){
            $result[]=['id'=>$latest['id'],'order_id'=>$id,'provider_host'=>$latest['provider_host'],'expires_at'=>$latest['expires_at']];
        }
    }
    return $result;
}
function dmo_checkout_apply(PDO $db,array $admin,int $request,array $input): never {
    $issue=$input['action']==='issue_checkout_link';
    if(!dmo_checkout_schema_ready($db)||($issue&&!dmo_checkout_enabled($db)))dmo_json(503,['ok'=>false,'error'=>'checkout_disabled']);
    $allowed=$issue?['action','request_id','order_id','expected_link_id','url','currency','amount_minor','confirm_invoice']:
        ['action','request_id','order_id','expected_link_id','confirm_external_closed'];
    if(count($input)!==count($allowed)||array_diff(array_keys($input),$allowed)!==[]||
        !array_key_exists('expected_link_id',$input)||($input['expected_link_id']!==null&&(!is_int($input['expected_link_id'])||$input['expected_link_id']<1))||
        ($issue&&(!dmo_checkout_url($input['url']??null)||($input['confirm_invoice']??null)!==true||
        !in_array($input['currency']??null,['CAD','USD'],true)||!is_int($input['amount_minor']??null)))||
        (!$issue&&($input['confirm_external_closed']??null)!==true))
        dmo_json(422,['ok'=>false,'error'=>'invalid_checkout_request']);
    $order=dmo_workflow_id($input['order_id']??null);
    if(!dmo_auth_rate_limit($db,'checkout-link',(string)$admin['user_id'],20,60))dmo_json(429,['ok'=>false,'error'=>'too_many_attempts']);
    $db->beginTransaction();
    try{
        // Same lock order as reconciliation; prevent reuse across orders.
        $mutex=$db->query("SELECT id FROM dmo_users WHERE role='admin' ORDER BY id LIMIT 1 FOR UPDATE");
        if($mutex->fetchColumn()===false)throw new DmoPaymentConflict('checkout_unavailable');
        $lock=$db->prepare('SELECT id FROM dmo_commission_requests WHERE id=:id FOR UPDATE');$lock->execute(['id'=>$request]);
        if($lock->fetchColumn()===false)throw new DmoPaymentConflict('request_not_found');
        $lock=$db->prepare('SELECT id FROM dmo_orders WHERE id=:id AND request_id=:request FOR UPDATE');$lock->execute(['id'=>$order,'request'=>$request]);
        if($lock->fetchColumn()===false)throw new DmoPaymentConflict('order_not_found');
        $latest=dmo_checkout_latest($db,$order);
        if(($latest['id']??null)!==$input['expected_link_id'])throw new DmoPaymentConflict('checkout_link_changed');
        if($issue){
            $r=dmo_checkout_eligible($db,$request,$order);
            if($r===null||$r['currency']!==$input['currency']||(int)$r['total_minor']!==$input['amount_minor'])
                throw new DmoPaymentConflict('full_quote_amount_required');
            if($latest!==null&&$latest['status']!=='revoked')throw new DmoPaymentConflict('close_previous_invoice_first');
            $fingerprint=hash_hmac('sha256',$input['url'],dmo_checkout_key());
            $q=$db->prepare('SELECT id FROM dmo_checkout_links WHERE url_fingerprint=:hash LIMIT 1');$q->execute(['hash'=>$fingerprint]);
            if($q->fetchColumn()!==false)throw new DmoPaymentConflict('invoice_already_used');
            $db->prepare("INSERT INTO dmo_checkout_links (order_id,created_by,url_ciphertext,url_fingerprint,provider_host,currency,amount_minor,expires_at) VALUES (:order,:actor,:cipher,:hash,:host,:currency,:amount,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 7 DAY))")
                ->execute(['order'=>$order,'actor'=>$admin['user_id'],'cipher'=>dmo_checkout_seal($input['url'],$order),'hash'=>$fingerprint,'host'=>dmo_checkout_config()['host'],'currency'=>$r['currency'],'amount'=>(int)$r['total_minor']]);
            $id=(int)$db->lastInsertId();$status='active';
        }else{
            if($latest===null||$latest['status']!=='active')throw new DmoPaymentConflict('checkout_not_active');
            $id=$latest['id'];$status='revoked';
            $db->prepare("UPDATE dmo_checkout_links SET status='revoked',revoked_at=UTC_TIMESTAMP(),url_ciphertext='' WHERE id=:id")->execute(['id'=>$id]);
        }
        dmo_workflow_audit($db,(int)$admin['user_id'],$request,'checkout.'.$status,['order_id'=>$order,'link_id'=>$id]);
        $db->commit();
    }catch(DmoPaymentConflict $e){if($db->inTransaction())$db->rollBack();dmo_json(409,['ok'=>false,'error'=>$e->getMessage()]);}
    catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    dmo_json($issue?201:200,['ok'=>true,'link_id'=>$id,'status'=>$status,'money_moved'=>false]);
}
