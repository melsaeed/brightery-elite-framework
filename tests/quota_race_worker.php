#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Spawned ONLY by concurrency_mysql.php against a dedicated test_ database. */
$dbName=(string)getenv('TEST_DB_DATABASE');
if(!preg_match('/^brightery_test_[A-Za-z0-9_]+$/D',$dbName))exit(2);
$tenantId=filter_var($argv[1]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$userId=filter_var($argv[2]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$path=(string)($argv[3]??'');
if(!$tenantId||!$userId||!is_file($path))exit(2);
putenv('DB_DATABASE='.$dbName);putenv('MEDIA_AUTO_THUMBNAILS=false');
$kernel=require dirname(__DIR__).'/bootstrap/app.php';
$kernel->service(\Brightery\Core\Tenancy\ContextStore::class)->set(new \Brightery\Core\Tenancy\TenantContext('tenant',(int)$tenantId));
try{
    $kernel->service(\Brightery\Core\Media\PrivateMedia::class)->store($path,'concurrent.png',(int)$userId);
    echo "STORED\n";
}catch(\RuntimeException $e){
    if($e->getMessage()==='Media storage quota exceeded'){echo "QUOTA_REJECTED\n";exit(0);}
    fwrite(STDERR,"Unexpected media storage failure: ".get_class($e)."\n");exit(1);
}
