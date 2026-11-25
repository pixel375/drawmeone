<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$root=dirname(__DIR__);
function route_assert(bool $ok,string $label): void {
    if(!$ok)throw new RuntimeException('ROUTE_CONTRACT_FAIL='.$label);
    echo "PASS: ".$label."\n";
}
function route_pattern(string $path): string {
    $file=file_get_contents($path);
    if(!is_string($file)||!preg_match('/^\\s*RewriteRule\\s+(\\S+)\\s+-\\s+\\[F,END\\]$/m',$file,$m))
        throw new RuntimeException('Missing Apache route allowlist');
    return '~'.$m[1].'~D';
}
$api=route_pattern($root.'/api/.htaccess');
$admin=route_pattern($root.'/api/admin/.htaccess');
$auth=route_pattern($root.'/api/auth/.htaccess');
foreach(glob($root.'/api/*.php')?:[] as $path){
    $name=basename($path);
    route_assert(preg_match($api,$name)===0,'public_api_'.$name);
}
foreach(['admin'=>$admin,'auth'=>$auth] as $dir=>$pattern){
    foreach(glob($root.'/api/'.$dir.'/*.php')?:[] as $path){
        $name=basename($path);
        route_assert(preg_match($api,$dir.'/'.$name)===0&&preg_match($pattern,$name)===0,'reviewed_'.$dir.'_'.$name);
    }
    route_assert(preg_match($api,$dir.'/unreviewed.php')===1&&preg_match($pattern,'unreviewed.php')===1,'unknown_'.$dir.'_denied');
}
foreach(['unknown.php','admin/finance.php/extra','admin/customers.php.bak','admin/finance.php.txt','.env','auth/unknown.php'] as $path)
    route_assert(preg_match($api,$path)===1,'unreviewed_path_denied');
echo "APACHE_ROUTE_CONTRACT=PASS\n";
