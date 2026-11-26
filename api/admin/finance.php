<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/finance-report.php';
dmo_reject_non_get();
try{
    $db=dmo_database();dmo_admin_require_session($db);
    $state=$_GET['state']??'all';$currency=$_GET['currency']??'all';$before=$_GET['before']??null;
    if(!in_array($state,['all','pending','reconciled','held','refunded','rejected','review'],true)||
       !in_array($currency,['all','CAD','USD'],true)||
       ($before!==null&&(!is_string($before)||!preg_match('/^[1-9][0-9]{0,17}$/D',$before))))
        dmo_json(422,['ok'=>false,'error'=>'invalid_finance_filter']);
    $from=dmo_finance_date($_GET['from']??null);$to=dmo_finance_date($_GET['to']??null);
    if($from!==null&&$to!==null&&$from>$to)dmo_json(422,['ok'=>false,'error'=>'invalid_finance_date_range']);
    $db->beginTransaction();
    $report=dmo_finance_report($db,$state,$currency,$before===null?null:(int)$before,$from,$to);
    $db->commit();dmo_json(200,$report);
}catch(Throwable $e){
    if(isset($db)&&$db->inTransaction())$db->rollBack();
    dmo_log_problem($e,'finance report');dmo_json(503,['ok'=>false,'error'=>'finance_report_unavailable']);
}
