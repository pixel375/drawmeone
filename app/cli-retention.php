<?php
declare(strict_types=1);
require_once __DIR__.'/retention.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==2||$argv[1]!=='report'){fwrite(STDERR,"Usage: php app/cli-retention.php report\n");exit(2);}
try{
    $report=dmo_retention_report(dmo_database());
    foreach($report['counts'] as $key=>$value)echo "retention_".$key."=".$value."\n";
    echo "retention_mode=review_only\n";
    exit(0);
}catch(Throwable $e){fwrite(STDERR,"retention_report=unavailable\n");exit(1);}
