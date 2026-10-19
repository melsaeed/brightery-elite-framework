#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Isolated multi-process atomic quota race test.
 * Set TEST_DB_DATABASE=brightery_test_<name> to an EXISTING, EMPTY disposable MySQL schema.
 * No database is created or dropped; never run against production.
 */
$dbName=(string)getenv('TEST_DB_DATABASE');
if(!preg_match('/^brightery_test_[A-Za-z0-9_]+$/D',$dbName)){
    fwrite(STDERR,"TEST_DB_DATABASE must start with brightery_test_\n");exit(2);
}
if(!extension_loaded('pdo_mysql')){fwrite(STDERR,"pdo_mysql required\n");exit(2);}
if(!function_exists('proc_open')){fwrite(STDERR,"proc_open required\n");exit(2);}
putenv('DB_DATABASE='.$dbName);putenv('MEDIA_AUTO_THUMBNAILS=false');putenv('MEDIA_SCAN_REQUIRED=false');putenv('MEDIA_SCAN_DRIVER=disabled');
$kernel=require dirname(__DIR__).'/bootstrap/app.php';
use Brightery\Core\{App,Database};
use Brightery\Core\Tenancy\{ContextStore,TenantContext};
use Brightery\Core\Media\{MediaQuota,PrivateMedia};
$db=App::get()->service(Database::class);
if((int)($db->row('SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=?',[$dbName])['n']??0)!==0){
    throw new RuntimeException('Test database must be empty; refusing to alter populated schema');
}
App::get()->service(\Brightery\Core\Database\Migrator::class)->run();
$user='race-'.bin2hex(random_bytes(4)).'@example.test';
$db->execute('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)',['Race User',$user,password_hash(bin2hex(random_bytes(32)),PASSWORD_ARGON2ID)]);
$userId=(int)$db->pdo()->lastInsertId();
$db->execute('INSERT INTO tenants(slug,display_name) VALUES(?,?)',['race-'.bin2hex(random_bytes(4)),'Quota Race']);
$tenantId=(int)$db->pdo()->lastInsertId();
$db->execute("INSERT INTO tenant_memberships(tenant_id,user_id,role) VALUES(?,?,'owner')",[$tenantId,$userId]);
$contexts=App::get()->service(ContextStore::class);$contexts->set(new TenantContext('tenant',$tenantId));
$quota=App::get()->service(MediaQuota::class);
$contexts->set(new TenantContext('central'));
$quota->setTenantLimits($tenantId,1048576,1);
$contexts->set(new TenantContext('tenant',$tenantId));
$file=tempnam(sys_get_temp_dir(),'bf-race-png');
file_put_contents($file,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7OPR8AAAAASUVORK5CYII='));
$jobs=[];try {
    for($i=0;$i<6;$i++){
        $spec=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];
        $proc=proc_open([PHP_BINARY, __DIR__.'/quota_race_worker.php',(string)$tenantId,(string)$userId,$file],$spec,$pipes,base_path());
        if(!is_resource($proc))throw new RuntimeException('Unable to spawn concurrent quota worker');
        fclose($pipes[0]);$jobs[]=[$proc,$pipes];
    }
    $results=[];
    foreach($jobs as [$proc,$pipes]){
        $out=trim(stream_get_contents($pipes[1]));$err=trim(stream_get_contents($pipes[2]));
        fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);
        if($code!==0)throw new RuntimeException('Worker failed: '.$err);
        $results[]=$out;
    }
    $ok=count(array_filter($results,static fn($out)=>$out==='STORED'));
    $blocked=count(array_filter($results,static fn($out)=>$out==='QUOTA_REJECTED'));
    if($ok!==1||$blocked!==5)throw new RuntimeException('Quota race failed: '.json_encode($results));
    $usage=$quota->snapshot();
    $rows=$db->rows('SELECT id,bytes FROM bf_private_media WHERE tenant_id=?',[$tenantId]);
    if(count($rows)!==1||$usage['original_count']!==1||$usage['used_bytes']!==(int)$rows[0]['bytes'])throw new RuntimeException('Quota counters inconsistent after race');
    if(!App::get()->service(PrivateMedia::class)->delete((int)$rows[0]['id'])||$quota->snapshot()['used_bytes']!==0)throw new RuntimeException('Quota not released after delete');
    echo "PASS: six concurrent processes, one stored, five rejected, quota accurate, deletion released bytes\n";
}finally{ @unlink($file);$contexts->clear(); }
