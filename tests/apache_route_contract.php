<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
function v2_route_pattern(string $p): string {
    if(!preg_match('/^\s*RewriteRule\s+(\S+)\s+-\s+\[F,END\]$/m',file_get_contents($p),$m))throw new RuntimeException('Missing route guard');
    return '~'.$m[1].'~D';
}
$root=dirname(__DIR__);$api=v2_route_pattern($root.'/api/.htaccess');$admin=v2_route_pattern($root.'/api/admin/.htaccess');$auth=v2_route_pattern($root.'/api/auth/.htaccess');
$public=['health.php','studio.php','portfolio.php','artwork.php'];$artist=['status.php','login.php','me.php','logout.php','studio.php','artworks.php','artwork-upload.php','artwork-preview.php','security.php'];
foreach(glob($root.'/api/*.php') as $p){$n=basename($p);if((preg_match($api,$n)===0)!==in_array($n,$public,true))throw new RuntimeException('Unexpected public route: '.$n);}
foreach(glob($root.'/api/admin/*.php') as $p){$n=basename($p);$allowed=in_array($n,$artist,true);if((preg_match($api,'admin/'.$n)===0)!==$allowed||(preg_match($admin,$n)===0)!==$allowed)throw new RuntimeException('Unexpected admin route: '.$n);}
foreach(glob($root.'/api/auth/*.php') as $p){$n=basename($p);if(preg_match($api,'auth/'.$n)!==1||preg_match($auth,$n)!==1)throw new RuntimeException('Legacy auth exposed');}
foreach(['admin/artworks.php/extra','studio.php.bak','unknown.php','admin/unknown.php','auth/signup.php'] as $n)if(preg_match($api,$n)!==1)throw new RuntimeException('Unreviewed path');
echo "V2_APACHE_ROUTE_CONTRACT=PASS\n";
