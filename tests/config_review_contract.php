<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/app/config-review.php';
function config_assert(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException('CONFIG_REVIEW_FAIL='.$label);
    echo "PASS: ".$label."\n";
}
$closed=dmo_config_review_flags([],[]);
config_assert($closed['issue_count']===0&&$closed['enabled_gate_count']===0&&$closed['authorizes_launch']===false,'closed_defaults_no_launch_authority');
$bad=dmo_config_review_flags(['DMO_CHECKOUT_LINKS_ENABLED'=>'1','DMO_AUTH_ENABLED'=>'synthetic secret not printable'],[]);
config_assert(in_array('invalid_boolean:DMO_AUTH_ENABLED',$bad['issues'],true)&&in_array('dependency:DMO_CHECKOUT_LINKS_ENABLED:DMO_MANUAL_PAYMENT_ENABLED',$bad['issues'],true)&&in_array('checkout_host_or_path_unavailable',$bad['issues'],true),'invalid_and_missing_dependencies_detected');
config_assert(!str_contains(json_encode($bad,JSON_THROW_ON_ERROR),'synthetic secret'),'raw_config_never_reported');
config_assert(dmo_config_review_duplicate_flags(['DMO_AUTH_ENABLED=0','DMO_AUTH_ENABLED=1','DB_PASS=secret','DB_PASS=other','# DMO_MAIL_ENABLED=1','DMO_MAIL_ENABLED=0'])===['DMO_AUTH_ENABLED'],'duplicate_known_flags_only');
config_assert(dmo_config_review_duplicate_flags(['DMO_APP_KEY=synthetic-first','DMO_APP_KEY=synthetic-second','DB_PASS=synthetic-first','DB_PASS=synthetic-second','UNREVIEWED_VALUE=first','UNREVIEWED_VALUE=second'],true)===['DMO_APP_KEY','DB_PASS'],'duplicate_critical_names_only_no_values');
$all=array_fill_keys(dmo_config_review_gate_names(),'1');
$ready=dmo_config_review_flags($all,['auth_ready'=>true,'admin_ready'=>true,'mail_ready'=>true,'policy_ready'=>true,'checkout_ready'=>true]);
config_assert($ready['issue_count']===0&&$ready['authorizes_launch']===false,'complete_configuration_still_not_approval');
$example=file_get_contents(dirname(__DIR__).'/docs/AUTH_PRIVATE_ENV.example');
foreach(dmo_config_review_gate_names() as $gate)config_assert(preg_match('/^'.preg_quote($gate,'/').'=0$/m',$example)===1,'example_closed_'.$gate);
echo "CONFIG_REVIEW_CONTRACT=PASS\n";
