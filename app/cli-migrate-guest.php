<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(count($argv)!==2||$argv[1]!=='apply'){fwrite(STDERR,"Fixed V2 migration requires apply\n");exit(2);}
require_once __DIR__.'/studio-v2.php';
try{
    $db=dmo_database();
    if(dmo_studio_load($db)['settings']['accepting']!==false)throw new RuntimeException('Close intake before migration');
    $sql=file_get_contents(dirname(__DIR__).'/database/007_guest_requests.sql');
    if(!is_string($sql)||strlen($sql)>5000||substr_count($sql,'CREATE TABLE IF NOT EXISTS dmo_guest_requests')!==1)throw new RuntimeException('Invalid migration');
    $parts=array_values(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$sql)?:[])));
    if(count($parts)!==2)throw new RuntimeException('Invalid migration statements');
    $db->exec($parts[0]);
    $columns=array_column($db->query('SHOW COLUMNS FROM dmo_guest_requests')->fetchAll(),'Field');
    foreach(['id','operation_hash','payload_digest','name','email','brief','budget','status','notice_state','created_at'] as $key)if(!in_array($key,$columns,true))throw new RuntimeException('Schema mismatch');
    $db->exec($parts[1]);
    if($db->query("SELECT version FROM dmo_schema_migrations WHERE version='007_guest_requests'")->fetchColumn()!=='007_guest_requests')throw new RuntimeException('Migration marker absent');
    echo "v2_guest_schema=ready\nv2_commissions=closed\n";
}catch(Throwable $e){fwrite(STDERR,"v2_guest_migration_failed=".get_class($e)."\n");exit(1);}
