<?php
declare(strict_types=1);
require_once __DIR__.'/integrity.php';
if(PHP_SAPI!=='cli'||count($argv)!==2||$argv[1]!=='check'){
    if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
    fwrite(STDERR,"Usage: php app/cli-integrity.php check\n");exit(2);
}
try{
    $db=dmo_database();
    $records=dmo_integrity_records($db);$media=dmo_integrity_media($db);
    $media['checkout_crypto']=dmo_integrity_checkout_crypto($db);
    $issues=array_sum($records);$complete=true;
    foreach($records as $key=>$value)echo "integrity_".$key."=".$value."\n";
    foreach($media as $key=>$row){
        echo "integrity_".$key."_checked=".$row['checked']."\n";
        echo "integrity_".$key."_invalid=".$row['invalid']."\n";
        echo "integrity_".$key."_complete=".($row['complete']?'yes':'no')."\n";
        $issues+=$row['invalid'];$complete=$complete&&$row['complete'];
    }
    echo "integrity_result=".($issues>0?'issues_found':($complete?'clean':'incomplete'))."\n";
    exit($issues===0&&$complete?0:1);
}catch(Throwable $e){
    fwrite(STDERR,"integrity_result=unavailable\n");exit(1);
}
