<?php
declare(strict_types=1);
require_once __DIR__.'/retention.php';
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)<2||count($argv)>3||!in_array($argv[1],['report','preview'],true)||
    ($argv[1]==='report'&&count($argv)!==2)||
    (isset($argv[2])&&!preg_match('/^(?:[1-9][0-9]{0,2}|1000)$/D',$argv[2]))){
    fwrite(STDERR,"Usage: php app/cli-retention.php report | preview [1-1000]\n");exit(2);
}
try{
    $db=dmo_database();
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $db->exec('START TRANSACTION READ ONLY');
    if($argv[1]==='preview'){
        $preview=dmo_retention_cleanup_preview($db,(int)($argv[2]??100));
        foreach($preview['batches'] as $key=>$batch){
            echo "retention_preview_".$key."=".$batch['preview_count']."\n";
            echo "retention_preview_".$key."_more=".($batch['more_candidates']?'yes':'no')."\n";
        }
        echo "retention_mode=preview_only\nretention_authorizes_deletion=no\n";
        $db->rollBack();exit(0);
    }
    $report=dmo_retention_report($db);
    $db->rollBack();
    foreach($report['counts'] as $key=>$value)echo "retention_".$key."=".$value."\n";
    echo "retention_mode=review_only\n";
    exit(0);
}catch(Throwable $e){fwrite(STDERR,"retention_report=unavailable\n");exit(1);}
