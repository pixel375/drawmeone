<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==2||!in_array($argv[1],['check','json'],true)){
    fwrite(STDERR,"Usage: php app/cli-launch-readiness.php check|json\n");exit(2);
}
require_once __DIR__.'/launch-readiness.php';
try{
    $report=dmo_launch_overview(dmo_database())['launch_evidence'];
    if($argv[1]==='json')echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    else{
        echo "launch_evidence_scope=configuration_only\n";
        echo "launch_authorized_by_report=no\n";
        foreach($report['stages'] as $stage)echo "launch_stage_".$stage['key']."=".($stage['configuration_ready']?'configured':'configuration_pending')."\n";
        echo "launch_external_evidence=operator_review_required\n";
    }
    // Successful generation is not a launch pass. External evidence is always separate.
    exit(0);
}catch(Throwable $error){fwrite(STDERR,"launch_evidence=unavailable\n");exit(1);}
