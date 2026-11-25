<?php
declare(strict_types=1);
require_once __DIR__.'/checkout.php';
require_once __DIR__.'/admin.php';

/** Fixed allowlist. No arbitrary env names, values, paths or secrets in reports. */
function dmo_config_review_gate_names(): array
{
    return ['DMO_AUTH_ENABLED',
        'DMO_REGISTRATION_ENABLED',
        'DMO_ADMIN_ENABLED',
        'DMO_MAIL_ENABLED',
        'DMO_ALLOW_PHP_MAIL',
        'DMO_DESIGN_WRITES_ENABLED',
        'DMO_PUBLIC_DESIGN_ENABLED',
        'DMO_PACKAGE_WRITES_ENABLED',
        'DMO_PACKAGE_PUBLISH_ENABLED',
        'DMO_PRICING_APPROVED',
        'DMO_REQUEST_ADMIN_WRITES_ENABLED',
        'DMO_ARTWORK_WRITES_ENABLED',
        'DMO_REQUESTS_ENABLED',
        'DMO_REQUEST_TERMS_APPROVED',
        'DMO_PRIVACY_PUBLISHED',
        'DMO_REFERENCES_ENABLED',
        'DMO_CUSTOMER_MESSAGING_ENABLED',
        'DMO_QUOTE_DELIVERY_ENABLED',
        'DMO_QUOTE_RESPONSES_ENABLED',
        'DMO_ORDER_ADMIN_ENABLED',
        'DMO_ORDER_TERMS_APPROVED',
        'DMO_CUSTOMER_NOTIFICATIONS_ENABLED',
        'DMO_DELIVERY_DRAFTS_ENABLED',
        'DMO_MANUAL_PAYMENT_ENABLED',
        'DMO_MANUAL_PAYMENT_TERMS_APPROVED',
        'DMO_DELIVERY_RELEASE_ENABLED',
        'DMO_DELIVERY_TERMS_APPROVED',
        'DMO_ARTWORK_REVIEW_ENABLED',
        'DMO_PAYMENT_EXCEPTIONS_ENABLED',
        'DMO_PAYMENT_EXCEPTIONS_TERMS_APPROVED',
        'DMO_INTAKE_CONTROL_ENABLED',
        'DMO_PRIVACY_SELF_SERVICE_ENABLED',
        'DMO_CHECKOUT_LINKS_ENABLED',
        'DMO_CHECKOUT_TERMS_APPROVED',
        'DMO_CANCELLATION_ENABLED',
        'DMO_CANCELLATION_TERMS_APPROVED'];
}
function dmo_config_review_flags(array $values,array $runtime): array
{
    $issues=[];$enabled=0;
    foreach(dmo_config_review_gate_names() as $name){
        if(!array_key_exists($name,$values))continue;
        if(!in_array($values[$name],['0','1'],true))$issues[]='invalid_boolean:'.$name;
        if($values[$name]==='1')$enabled++;
    }
    $on=static fn(string $key):bool=>($values['DMO_'.$key]??'0')==='1';
    $dependencies=[
        'REGISTRATION_ENABLED'=>['AUTH_ENABLED','PRIVACY_PUBLISHED','REQUEST_TERMS_APPROVED'],
        'REQUESTS_ENABLED'=>['AUTH_ENABLED','PRIVACY_PUBLISHED','REQUEST_TERMS_APPROVED'],
        'REFERENCES_ENABLED'=>['AUTH_ENABLED','PRIVACY_PUBLISHED','REQUEST_TERMS_APPROVED'],
        'CUSTOMER_MESSAGING_ENABLED'=>['AUTH_ENABLED','PRIVACY_PUBLISHED','REQUEST_TERMS_APPROVED'],
        'QUOTE_DELIVERY_ENABLED'=>['AUTH_ENABLED','PRIVACY_PUBLISHED','REQUEST_TERMS_APPROVED','REQUEST_ADMIN_WRITES_ENABLED'],
        'QUOTE_RESPONSES_ENABLED'=>['AUTH_ENABLED','PRIVACY_PUBLISHED','REQUEST_TERMS_APPROVED'],
        'ORDER_ADMIN_ENABLED'=>['AUTH_ENABLED','PRIVACY_PUBLISHED','REQUEST_TERMS_APPROVED','ORDER_TERMS_APPROVED','REQUEST_ADMIN_WRITES_ENABLED'],
        'PACKAGE_PUBLISH_ENABLED'=>['PACKAGE_WRITES_ENABLED','PRICING_APPROVED'],
        'MANUAL_PAYMENT_ENABLED'=>['ORDER_ADMIN_ENABLED','MANUAL_PAYMENT_TERMS_APPROVED'],
        'CHECKOUT_LINKS_ENABLED'=>['MANUAL_PAYMENT_ENABLED','CHECKOUT_TERMS_APPROVED'],
        'PAYMENT_EXCEPTIONS_ENABLED'=>['ORDER_ADMIN_ENABLED','PAYMENT_EXCEPTIONS_TERMS_APPROVED'],
        'DELIVERY_DRAFTS_ENABLED'=>['ORDER_ADMIN_ENABLED'],
        'DELIVERY_RELEASE_ENABLED'=>['AUTH_ENABLED','PRIVACY_PUBLISHED','REQUEST_TERMS_APPROVED','DELIVERY_TERMS_APPROVED'],
        'ARTWORK_REVIEW_ENABLED'=>['DELIVERY_RELEASE_ENABLED'],
        'CUSTOMER_NOTIFICATIONS_ENABLED'=>['AUTH_ENABLED','PRIVACY_PUBLISHED','REQUEST_TERMS_APPROVED','REQUEST_ADMIN_WRITES_ENABLED','MAIL_ENABLED'],
        'PRIVACY_SELF_SERVICE_ENABLED'=>['AUTH_ENABLED'],
        'CANCELLATION_ENABLED'=>['AUTH_ENABLED','CANCELLATION_TERMS_APPROVED'],
    ];
    foreach($dependencies as $gate=>$requires)if($on($gate))foreach($requires as $required)if(!$on($required))$issues[]='dependency:DMO_'.$gate.':DMO_'.$required;
    if($on('AUTH_ENABLED')&&!($runtime['auth_ready']??false))$issues[]='customer_auth_configuration_incomplete';
    if($on('ADMIN_ENABLED')&&!($runtime['admin_ready']??false))$issues[]='admin_auth_configuration_incomplete';
    if($on('MAIL_ENABLED')&&!($runtime['mail_ready']??false))$issues[]='mail_configuration_incomplete';
    if(($on('PRIVACY_PUBLISHED')||$on('REQUEST_TERMS_APPROVED'))&&!($runtime['policy_ready']??false))$issues[]='approved_policy_bundle_or_pin_unavailable';
    if($on('CHECKOUT_LINKS_ENABLED')&&!($runtime['checkout_ready']??false))$issues[]='checkout_host_or_path_unavailable';
    return ['mode'=>'review_only','enabled_gate_count'=>$enabled,'known_gate_count'=>count(dmo_config_review_gate_names()),'issues'=>$issues,'issue_count'=>count($issues),'authorizes_launch'=>false];
}
function dmo_config_review_duplicate_flags(array $lines): array
{
    $known=array_fill_keys(dmo_config_review_gate_names(),true);$seen=[];$duplicates=[];
    foreach($lines as $line){
        if(!is_string($line)||!preg_match('/^([A-Z][A-Z0-9_]*)\\s*=/D',trim($line),$match)||!isset($known[$match[1]]))continue;
        $key=$match[1];if(isset($seen[$key]))$duplicates[$key]=true;$seen[$key]=true;
    }
    return array_keys($duplicates);
}
function dmo_config_review(): array
{
    $values=[];$loaded=dmo_load_env();
    foreach(dmo_config_review_gate_names() as $key){
        if(array_key_exists($key,$loaded))$values[$key]=$loaded[$key];
        else{$value=getenv($key);if(is_string($value))$values[$key]=$value;}
    }
    $report=dmo_config_review_flags($values,[
        'auth_ready'=>dmo_auth_configured(),'admin_ready'=>dmo_admin_enabled(),
        'mail_ready'=>dmo_mail_ready(),'policy_ready'=>dmo_policy_current()!==null,
        'checkout_ready'=>dmo_checkout_config()!==null,
    ]);
    $path=dmo_private_env_path();
    if($path===null)$report['issues'][]='private_configuration_file_unavailable';
    else{
        $mode=@fileperms($path);
        if($mode===false||($mode&0077)!==0)$report['issues'][]='private_configuration_permissions_review';
        $lines=@file($path,FILE_IGNORE_NEW_LINES);
        if($lines===false)$report['issues'][]='private_configuration_read_failed';
        else foreach(dmo_config_review_duplicate_flags($lines) as $key)$report['issues'][]='duplicate_boolean:'.$key;
    }
    $report['issue_count']=count($report['issues']);
    return $report;
}
