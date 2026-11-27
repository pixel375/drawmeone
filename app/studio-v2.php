<?php
declare(strict_types=1);
require_once __DIR__.'/admin.php';

/** Public website preferences, separate from the retired V1 design state.
 * Stored as versioned non-secret configuration in the existing backed-up audit table.
 * No personal customer data or payment credentials belong here. */
function dmo_studio_defaults(): array {
    return ['title'=>'A little art, just for you.','intro'=>'Fan art, original characters and custom drawings. Thanks for stopping by!','price'=>'','payments'=>'','instagram'=>'','tiktok'=>'','default_mode'=>'dark','theme'=>'blurple','layout'=>'showcase','discord_enabled'=>false,'discord_url'=>'','guest_captcha'=>true,'accepting'=>false];
}
function dmo_studio_normalize(mixed $raw): ?array {
    if(!is_array($raw)||array_is_list($raw)||array_diff(array_keys($raw),array_keys(dmo_studio_defaults()))!==[])return null;
    $out=dmo_studio_defaults();
    foreach(['title'=>80,'intro'=>500,'price'=>120,'payments'=>180,'instagram'=>180,'tiktok'=>180,'discord_url'=>180] as $key=>$max){
        $v=$raw[$key]??$out[$key];
        if(!is_string($v)||strlen($v)>$max||preg_match('//u',$v)!==1)return null;
        if($key==='intro')$v=str_replace(["\r\n","\r"],"\n",$v);
        if(preg_match($key==='intro'?'/[\x00-\x09\x0b-\x1f\x7f]/u':'/[\x00-\x1f\x7f]/u',$v)!==0)return null;
        $out[$key]=trim($v);
    }
    if($out['title']===''||$out['intro']==='')return null;
    foreach(['instagram'=>'www.instagram.com','tiktok'=>'www.tiktok.com'] as $key=>$host){
        if($out[$key]==='')continue;
        $u=parse_url($out[$key]);if(!is_array($u)||($u['scheme']??'')!=='https'||($u['host']??'')!==$host||isset($u['user'],$u['pass'])||isset($u['user'])||isset($u['pass'])||isset($u['port'])||isset($u['query'])||isset($u['fragment'])||!preg_match('~^/@?[A-Za-z0-9_.-]+/?$~D',$u['path']??''))return null;
    }
    if($out['discord_url']!==''){
        $u=parse_url($out['discord_url']);
        if(!is_array($u)||($u['scheme']??'')!=='https'||array_diff(array_keys($u),['scheme','host','path'])!==[]||
          !(($u['host']??'')==='discord.gg'&&preg_match('~^/[A-Za-z0-9_-]{2,64}/?$~D',$u['path']??'')||($u['host']??'')==='discord.com'&&preg_match('~^/invite/[A-Za-z0-9_-]{2,64}/?$~D',$u['path']??'')))return null;
    }
    if(array_key_exists('discord_enabled',$raw)&&!is_bool($raw['discord_enabled']))return null;
    $out['discord_enabled']=$raw['discord_enabled']??false;
    if($out['discord_enabled']&&$out['discord_url']==='')return null;
    if(array_key_exists('theme',$raw)&&!in_array($raw['theme'],['blurple','midnight','paper'],true))return null;
    $out['theme']=$raw['theme']??'blurple';
    if(array_key_exists('layout',$raw)&&!in_array($raw['layout'],['showcase','gallery','sidebar','editorial'],true))return null;
    $out['layout']=$raw['layout']??'showcase';
    if(array_key_exists('guest_captcha',$raw)&&!is_bool($raw['guest_captcha']))return null;
    $out['guest_captcha']=$raw['guest_captcha']??true;
    if(isset($raw['default_mode'])&&!in_array($raw['default_mode'],['dark','light'],true))return null;
    $out['default_mode']=$raw['default_mode']??'dark';
    if(array_key_exists('accepting',$raw)&&!is_bool($raw['accepting']))return null;
    $out['accepting']=$raw['accepting']??false;
    return $out;
}
function dmo_studio_load(PDO $db): array {
    $row=$db->query("SELECT id,details_json FROM dmo_audit_log WHERE action='studio.v2.settings' AND entity_type='user' ORDER BY id DESC LIMIT 1")->fetch();
    if(!is_array($row))return ['version'=>0,'settings'=>dmo_studio_defaults()];
    $settings=dmo_studio_normalize(json_decode((string)$row['details_json'],true));
    if($settings===null)throw new RuntimeException('Invalid studio settings');
    return ['version'=>(int)$row['id'],'settings'=>$settings];
}

/** One server-side availability control; missing schema/security fails closed. */
function dmo_guest_ready(PDO $db): bool {
    return dmo_guest_policy_ready($db,dmo_studio_load($db)['settings']);
}
function dmo_guest_policy_ready(PDO $db,array $settings): bool {
    return strlen(dmo_config('DMO_APP_KEY'))>=32&&dmo_guest_schema_ready($db)&&(!$settings['guest_captcha']||(dmo_config('DMO_TURNSTILE_SITE_KEY')!==''&&dmo_config('DMO_TURNSTILE_SECRET')!==''&&extension_loaded('curl')));
}
function dmo_guest_schema_ready(PDO $db): bool {
    try {
        if($db->query("SELECT version FROM dmo_schema_migrations WHERE version='007_guest_requests'")->fetchColumn()!=='007_guest_requests')return false;
        $db->query("SELECT id,operation_hash,payload_digest,name,email,brief,budget,status,notice_state,created_at FROM dmo_guest_requests LIMIT 0")->closeCursor();
        return true;
    }catch(Throwable $e){return false;}
}
function dmo_studio_lock(PDO $db): void {
    if($db->query("SELECT id FROM dmo_users WHERE role='admin' ORDER BY id LIMIT 1 FOR UPDATE")->fetchColumn()===false)throw new RuntimeException('Studio lock unavailable');
}
