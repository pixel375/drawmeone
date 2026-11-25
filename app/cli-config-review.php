<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==2||!in_array($argv[1],['check','json'],true)){fwrite(STDERR,"Usage: php app/cli-config-review.php check|json\n");exit(2);}
require_once __DIR__.'/config-review.php';
try{
    $report=dmo_config_review();
    if($argv[1]==='json')echo json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
    else{
        echo "configuration_review=generated\nconfiguration_review_issue_count=".$report['issue_count']."\n";
        foreach($report['issues'] as $issue)echo "configuration_review_issue=".$issue."\n";
        echo "configuration_review_authorizes_launch=no\n";
    }
    // Exit 0 means generated, never a launch authorization. Warnings can be
    // intentional during phased setup; review them separately.
    exit(0);
}catch(Throwable $e){fwrite(STDERR,"configuration_review=unavailable\n");exit(1);}
