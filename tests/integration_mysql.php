#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Requires a dedicated existing database: TEST_DB_DATABASE=brightery_test_anything php tests/integration_mysql.php */
$name=(string)getenv('TEST_DB_DATABASE');
if(!preg_match('/^brightery_test_[a-zA-Z0-9_]+$/D',$name)){
    fwrite(STDERR,"Refusing to touch non-test database; set TEST_DB_DATABASE=brightery_test_<name> first.\n");exit(2);
}
if(!extension_loaded('pdo_mysql')){fwrite(STDERR,"pdo_mysql missing: DB integration not run.\n");exit(2);}
putenv('DB_DATABASE='.$name);
putenv('TENANCY_MODE=single');
$kernel=require dirname(__DIR__).'/bootstrap/app.php';
use Brightery\Core\{App,Database,Security};
use Brightery\Core\Tenancy\{TenantInvitations,TenantMemberships,TenantRecords,TenantContext,ContextStore};
use Brightery\Core\Database\Migrator;
function assertTest(bool $ok,string $name):void {if(!$ok)throw new RuntimeException('FAIL: '.$name);echo 'PASS: '.$name."\n";}
$db=App::get()->service(Database::class);
// This file does not drop data; the caller should create a disposable test database.
if($db->row("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=?",[$name])['n']??0)throw new RuntimeException('Test database must be EMPTY to prevent overwriting fixtures');
App::get()->service(Migrator::class)->run();
$unique=bin2hex(random_bytes(4));
$db->execute('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)',['Initial Owner','owner-'.$unique.'@example.test',password_hash('MyVeryStrongPassword123',PASSWORD_ARGON2ID)]);
$ownerId=(int)$db->pdo()->lastInsertId();
foreach(['alpha-'.$unique,'beta-'.$unique] as $slug)$db->execute('INSERT INTO tenants(slug,display_name) VALUES(?,?)',[$slug,$slug]);
$tenantA=(int)$db->row('SELECT id FROM tenants WHERE slug=?',['alpha-'.$unique])['id'];
$tenantB=(int)$db->row('SELECT id FROM tenants WHERE slug=?',['beta-'.$unique])['id'];
$db->execute("INSERT INTO tenant_memberships(tenant_id,user_id,role) VALUES(?,?,'owner')",[$tenantA,$ownerId]);
$context=App::get()->service(ContextStore::class);$context->set(new TenantContext('tenant',$tenantA));
App::get()->service(Security::class)->login($ownerId);
$inv=App::get()->service(TenantInvitations::class);
$token=$inv->issue($tenantA,'new-'.$unique.'@example.test','owner',$ownerId);
assertTest(strlen($token)===64&&$inv->inspect($tenantA,$token)!==null&&$inv->inspect($tenantB,$token)===null,'invitations are tenant bound');
$newUser=$inv->accept($tenantA,$token,'New Owner','MyVeryStrongPassword456');
assertTest($newUser>0&&$inv->inspect($tenantA,$token)===null,'invitation consumed exactly once');
try{$inv->accept($tenantA,$token,'New Owner','MyVeryStrongPassword456');throw new RuntimeException('Token replay accepted');}
catch(RuntimeException $e){assertTest($e->getMessage()!=='Token replay accepted','invitation replay rejected');}
$members=App::get()->service(TenantMemberships::class);
assertTest(count($members->list($tenantA))===2,'memberships are tenant-isolated');
try{$members->list($tenantB);throw new RuntimeException('Foreign membership listing permitted');}
catch(RuntimeException $e){assertTest($e->getMessage()!=='Foreign membership listing permitted','foreign tenant listing is forbidden');}
$members->remove($tenantA,$ownerId,$newUser);
try{$members->remove($tenantA,$ownerId,$ownerId);throw new RuntimeException('Last owner removed');}
catch(RuntimeException $e){assertTest($e->getMessage()!=='Last owner removed','last owner removal blocked');}
$db->execute('CREATE TABLE bf_integration_records(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT UNSIGNED NOT NULL,note VARCHAR(80) NOT NULL,INDEX(tenant_id)) ENGINE=InnoDB');
$ctx=new ContextStore();$records=new TenantRecords($db,$ctx);$ctx->set(new TenantContext('tenant',$tenantA));
$recordId=$records->insert('bf_integration_records',['note'=>'alpha secret']);
$ctx->set(new TenantContext('tenant',$tenantB));
assertTest($records->find('bf_integration_records',$recordId)===null&&$records->all('bf_integration_records')===[],'record reads isolated across tenants');
$ctx->clear();
try{$records->all('bf_integration_records');throw new RuntimeException('Tenantless read');}
catch(LogicException $e){assertTest(true,'tenantless access fails closed');}
$db->execute('DROP TABLE bf_integration_records');
// v0.4: run on the same disposable schema; never against real customer data.
$registry=App::get()->service(\Brightery\Core\Jobs\Registry::class);
$queue=App::get()->service(\Brightery\Core\Jobs\Queue::class);
$testRuns=[];
$registry->register('test.capture',static function(array $payload)use(&$testRuns):void {$testRuns[]=$payload['marker'];});
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
$jobId=$queue->dispatch('test.capture',['marker'=>'tenant-A']);
assertTest($jobId>0,'tenant job queued');
App::get()->service(\Brightery\Core\Jobs\Worker::class)->one();
assertTest($testRuns===['tenant-A']&&$queue->stats()['completed']===1,'DB queue claim and worker execute tenant task');
try{App::get()->service(\Brightery\Core\Tenancy\ContextStore::class)->current();throw new RuntimeException('Worker leaked tenant context');}
catch(LogicException){assertTest(true,'queue worker clears tenant context');}
$context->set(new \Brightery\Core\Tenancy\TenantContext('central'));
$scheduler=App::get()->service(\Brightery\Core\Scheduler\Schedule::class);
$scheduler->everyMinutes('test.capture.every','test.capture',1,['marker'=>'scheduled']);
$time=new DateTimeImmutable('2026-10-16T04:11:00+00:00');
assertTest($scheduler->tick($time)===1&&$scheduler->tick($time)===0,'UTC scheduling is unique per task/slot');
App::get()->service(\Brightery\Core\Jobs\Worker::class)->one();
assertTest(in_array('scheduled',$testRuns,true),'scheduled job executed by worker');
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
$audits=App::get()->service(\Brightery\Core\Audit\AuditTrail::class);
$audits->record('test.security.logged',$ownerId,'user',(string)$ownerId,['api_token'=>'never-save']);
assertTest(count($audits->recent())>0&&!str_contains($audits->recent()[0]['metadata'],'never-save'),'audit trail tenant-scoped and redacts secrets');
$tokens=App::get()->service(\Brightery\Core\Api\TokenService::class);
$raw=$tokens->issue($ownerId,$tenantA,'integration-suite',['profile:read','workspace:read'],1);
assertTest($tokens->identify($raw,new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA))!==null,'API token authenticates for own active tenant');
assertTest($tokens->identify($raw,new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantB))===null,'tenant API token denied on another company');
$tokenId=\Brightery\Core\Api\TokenService::parse($raw)['id'];
assertTest($tokens->revoke($tokenId,$ownerId)&&$tokens->identify($raw,new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA))===null,'API token revocation effective immediately');
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantB));
assertTest($audits->recent()===[],'audit reads cannot cross tenants');
$context->clear();
if(!preg_match('/^[a-f0-9]{64}$/i',(string)env('APP_KEY','')))putenv('APP_KEY='.bin2hex(random_bytes(32)));
$totp=App::get()->service(\Brightery\Core\Mfa\Totp::class);
$totpSecret=$totp->enroll($ownerId);
$timeCode=\Brightery\Core\Mfa\Totp::code(\Brightery\Core\Mfa\Totp::decodeBase32($totpSecret),intdiv(time(),30));
assertTest($totp->activate($ownerId,$timeCode)&&$totp->enabled($ownerId),'TOTP enrollment requires live valid code');
assertTest(!$totp->verify($ownerId,$timeCode),'TOTP one-time code replay denied');
// v0.5 coverage: atomic rate limits, revocable sessions, tenant media and one-time recovery.
$rates=App::get()->service(\Brightery\Core\RateBuckets::class);
assertTest($rates->hit($tenantA,'integration.api','alice',2,60) && $rates->hit($tenantA,'integration.api','alice',2,60) && !$rates->hit($tenantA,'integration.api','alice',2,60),'DB rate limits enforce cross-process fixed-window quota');
assertTest($rates->hit($tenantB,'integration.api','alice',2,60),'DB rate buckets remain tenant separated');
$sessions=App::get()->service(\Brightery\Core\Auth\SessionRegistry::class);
$ctxString='integration-session-'.bin2hex(random_bytes(16));
$sessions->record($ctxString,$ownerId,$tenantA);
assertTest($sessions->valid($ctxString,$ownerId,$tenantA) && !$sessions->valid($ctxString,$ownerId,$tenantB),'session registry checks account and company scope');
$sessions->revoke($ctxString);
assertTest(!$sessions->valid($ctxString,$ownerId,$tenantA),'revoking a session invalidates it');
$codes=App::get()->service(\Brightery\Core\Mfa\RecoveryCodes::class)->rotate($ownerId);
assertTest(count($codes)===8 && App::get()->service(\Brightery\Core\Mfa\RecoveryCodes::class)->consume($ownerId,$codes[0]),'MFA codes created and single code accepted');
assertTest(!App::get()->service(\Brightery\Core\Mfa\RecoveryCodes::class)->consume($ownerId,$codes[0]),'MFA code replay denied');
$photo=tempnam(sys_get_temp_dir(),'bf-media-png');
file_put_contents($photo,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7OPR8AAAAASUVORK5CYII='));
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
$private=App::get()->service(\Brightery\Core\Media\PrivateMedia::class);
$mediaId=$private->store($photo,'logo.png',$ownerId);
assertTest($mediaId>0 && $private->metadata($mediaId)['original_name']==='logo.png','private media stored and indexed with tenant key');
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantB));
assertTest($private->metadata($mediaId)===null && $private->read($mediaId)===null,'private media cannot cross tenant');
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
assertTest($private->delete($mediaId),'tenant can delete own media');
@unlink($photo);
// Email token is generated & digested atomically with the encrypted outbox and queue row.
putenv('MAIL_DRIVER=log');putenv('MAIL_FROM=noreply@example.test');putenv('APP_ENV=local');
$verification=App::get()->service(\Brightery\Core\Mail\EmailVerification::class);
assertTest($verification->request($newUser,'http://127.0.0.1:8000'),'verification mail queued');
$tokenRow=$db->row('SELECT digest FROM bf_email_verification_tokens WHERE user_id=?',[$newUser]);
$mailRow=$db->row('SELECT id,payload_cipher FROM bf_email_outbox WHERE user_id=? ORDER BY id DESC LIMIT 1',[$newUser]);
$plaintext=App::get()->service(\Brightery\Core\Crypto\Encrypter::class)->decrypt($mailRow['payload_cipher']);
$data=json_decode($plaintext,true,16,JSON_THROW_ON_ERROR);
preg_match('~/verify-email/([a-f0-9]{64})~',$data['body'],$match);
assertTest(isset($match[1]) && hash('sha256',$match[1])===$tokenRow['digest'] && !str_contains($mailRow['payload_cipher'],$match[1]),'outbox encrypted; verification digest stored only');
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantB));
assertTest(!$verification->consume($match[1]),'email verification token rejected across tenant');
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
assertTest($verification->consume($match[1]) && $verification->verified($newUser),'email verified once within owning tenant');
assertTest(!$verification->consume($match[1]),'email verification token replay denied');
App::get()->service(\Brightery\Core\Mail\Outbox::class)->deliver((int)$mailRow['id']);
assertTest($db->row('SELECT status FROM bf_email_outbox WHERE id=?',[$mailRow['id']])['status']==='delivered','local mail transport flushes queued message');
$context->clear();
// v0.6: invitation delivery stays encrypted and scoped to an exact tenant domain.
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
$db->execute('INSERT INTO tenant_domains(tenant_id,hostname) VALUES(?,?)',[$tenantA,'alpha-'.$unique.'.example.test']);
$inviteEmail='secure-invite-'.$unique.'@example.test';
$emailedToken=$inv->issue($tenantA,$inviteEmail,'viewer',$ownerId,'https://alpha-'.$unique.'.example.test');
$inviteOutbox=$db->row('SELECT payload_cipher FROM bf_email_outbox WHERE recipient=? ORDER BY id DESC LIMIT 1',[$inviteEmail]);
$inviteBody=json_decode(App::get()->service(\Brightery\Core\Crypto\Encrypter::class)->decrypt($inviteOutbox['payload_cipher']),true,16,JSON_THROW_ON_ERROR)['body'];
assertTest(str_contains($inviteBody,'/invite/'.$emailedToken)&&!str_contains($inviteOutbox['payload_cipher'],$emailedToken),'invitations are encrypted in delivery queue');
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantB));
assertTest($inv->inspect($tenantB,$emailedToken)===null,'email invitation cannot be opened for another tenant');
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
$pw=App::get()->service(\Brightery\Core\Mail\PasswordReset::class);
$pw->request('owner-'.$unique.'@example.test','https://alpha-'.$unique.'.example.test');
$resetCipher=$db->row('SELECT payload_cipher FROM bf_email_outbox WHERE user_id=? ORDER BY id DESC LIMIT 1',[$ownerId])['payload_cipher'];
$resetBody=json_decode(App::get()->service(\Brightery\Core\Crypto\Encrypter::class)->decrypt($resetCipher),true,16,JSON_THROW_ON_ERROR)['body'];
preg_match('~/reset-password/([a-f0-9]{64})~',$resetBody,$resetTokenMatch);
$resetToken=$resetTokenMatch[1]??'';
assertTest(\Brightery\Core\Mail\PasswordReset::validToken($resetToken)&&!str_contains($resetCipher,$resetToken),'password reset tokens are encrypted and digest-only at rest');
$testApiToken=$tokens->issue($ownerId,$tenantA,'pwreset-revoke',['workspace:read'],1);
$sessionKey='reset-test-'.bin2hex(random_bytes(16));$sessions->record($sessionKey,$ownerId,$tenantA);
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantB));
assertTest(!$pw->consume($resetToken,'NewSuperSecurePassword567'),'password token cannot be consumed on another tenant');
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
assertTest($pw->consume($resetToken,'NewSuperSecurePassword567'),'password reset can be consumed once within own tenant');
assertTest(!$pw->consume($resetToken,'NewSuperSecurePassword890'),'password reset replay blocked');
assertTest(password_verify('NewSuperSecurePassword567',$db->row('SELECT password_hash FROM users WHERE id=?',[$ownerId])['password_hash']),'new password uses a salted secure hash');
assertTest(!$sessions->valid($sessionKey,$ownerId,$tenantA)&&$tokens->identify($testApiToken,new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA))===null,'reset revokes all web sessions and API tokens');
if(extension_loaded('gd')){
    $image=imagecreatetruecolor(120,90);$white=imagecolorallocate($image,250,250,250);imagefill($image,0,0,$white);
    $src=tempnam(sys_get_temp_dir(),'bf-real-png');imagepng($image,$src);imagedestroy($image);
    $original=$private->store($src,'portrait.png',$ownerId);
    $variants=App::get()->service(\Brightery\Core\Media\ImageVariants::class);
    $derived=$variants->create($original,64,64,'cover');
    assertTest(($variants->read($derived)['mime']??'')==='image/jpeg','GD creates private derived JPEG');
    $context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantB));
    assertTest($variants->read($derived)===null,'derived images cannot cross tenant');
    $context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
    assertTest($private->delete($original)&&$variants->read($derived)===null,'deleting parent removes derived records');
    @unlink($src);
}else echo "SKIP: GD image variant integration requires ext-gd.\n";
// v0.7.0: real InnoDB quota transactions and cross-tenant isolation.
$quota=App::get()->service(\Brightery\Core\Media\MediaQuota::class);
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
putenv('MEDIA_AUTO_THUMBNAILS=false');
try{$quota->setTenantLimits($tenantA,1048576,1);throw new RuntimeException('Tenant modified its storage limit');}
catch(RuntimeException $e){assertTest($e->getMessage()!=='Tenant modified its storage limit','tenant cannot set own media quota');}
$context->set(new \Brightery\Core\Tenancy\TenantContext('central'));
$quota->setTenantLimits($tenantA,1048576,1);
$quota->setTenantLimits($tenantB,1048576,1);
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
$db->execute("INSERT INTO tenant_memberships(tenant_id,user_id,role) VALUES(?,?,'editor')",[$tenantB,$ownerId]);
$sample=tempnam(sys_get_temp_dir(),'bf-q-png');
file_put_contents($sample,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7OPR8AAAAASUVORK5CYII='));
$size=filesize($sample);
$first=$private->store($sample,'quota.png',$ownerId);
$usage=$quota->snapshot();
assertTest($usage['used_bytes']===$size && $usage['original_count']===1,'tenant A atomic quota reserves size and file');
try{$private->store($sample,'second.png',$ownerId);throw new RuntimeException('Extra file accepted');}
catch(RuntimeException $e){assertTest($e->getMessage()!=='Extra file accepted','file count limit rejects second upload');}
assertTest($quota->snapshot()['original_count']===1,'quota rejection does not change usage');
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantB));
$other=$private->store($sample,'other.png',$ownerId);
assertTest($quota->snapshot()['original_count']===1 && $private->metadata($first)===null,'tenant B has distinct quota and cannot access tenant A');
assertTest($private->delete($other) && $quota->snapshot()['used_bytes']===0,'tenant B deletion releases own bytes');
$context->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
assertTest($private->delete($first) && $quota->snapshot()['original_count']===0,'deleting tenant A media releases original quota');
putenv('MEDIA_SCAN_REQUIRED=true');
try{$private->store($sample,'scanner-required.png',$ownerId);throw new RuntimeException('Unscanned file accepted');}
catch(RuntimeException $e){assertTest($e->getMessage()!=='Unscanned file accepted','enforced scanning refuses disabled scanner before write');}
putenv('MEDIA_SCAN_REQUIRED=false');
assertTest($quota->snapshot()['used_bytes']===0,'rejected scanner upload consumes no quota');
putenv('MEDIA_AUTO_THUMBNAILS=true');
$queueTarget=$private->store($sample,'queued.png',$ownerId);
$jobRow=$db->row("SELECT tenant_id FROM bf_jobs WHERE handler='system.media.thumbnail' AND tenant_id=? AND payload LIKE ? ORDER BY id DESC LIMIT 1",[$tenantA,'%"media_id":'.$queueTarget.'%']);
assertTest($jobRow!==null,'thumbnail queued with original tenant scope');
if(extension_loaded('gd')){
    $handler=App::get()->service(\Brightery\Core\Jobs\Registry::class);
    $handler->run('system.media.thumbnail',['media_id'=>$queueTarget]);
    $variants=App::get()->service(\Brightery\Core\Media\ImageVariants::class);
    assertTest(count($variants->list($queueTarget))===1,'GD thumbnail handler creates private 320px variant');
    $before=$quota->snapshot();
    assertTest($before['variant_count']===1 && $before['used_bytes']>$size,'derived thumbnail consumes storage quota');
    $handler->run('system.media.thumbnail',['media_id'=>$queueTarget]);
    assertTest($quota->snapshot()['variant_count']===1,'idempotent thumbnail generation does not double-bill');
}else echo "SKIP: v0.7 thumbnail creation requires ext-gd.\n";
assertTest($private->delete($queueTarget) && $quota->snapshot()['used_bytes']===0,'parent media and variants fully release quota');
@unlink($sample);
$context->clear();
// v0.9 worker registry and scoped quota reconciliation on disposable MySQL only.
$workerRegistry=App::get()->service(\Brightery\Core\Operations\WorkerRegistry::class);
$wid=\Brightery\Core\Operations\WorkerRegistry::workerId();
$workerRegistry->start($wid);$workerRegistry->beat($wid,'working');$workerRegistry->beat($wid,'idle',true);
$recent=$workerRegistry->recent();
assertTest(count($recent)>0 && $recent[0]['worker_id']===$wid && $recent[0]['jobs_processed']===1,'worker registration, heartbeat and completion recorded');
$workerRegistry->stop($wid);
assertTest(!$workerRegistry->recent()[0]['healthy'],'stopped workers are not counted active');
$reconciler=new \Brightery\Core\Operations\MediaReconciler($db,base_path('storage/private/media'));
$initial=$reconciler->preview($tenantA);
assertTest($initial['drift']['used_bytes']===0 && $initial['drift']['original_count']===0,'tenant A media usage matches records');
$db->execute('UPDATE bf_media_usage SET used_bytes=used_bytes+10 WHERE scope_id=?',[$tenantA]);
$drift=$reconciler->preview($tenantA);
assertTest($drift['drift']['used_bytes']===-10 && $reconciler->preview($tenantB)['drift']['used_bytes']===0,'tenant-scoped reconciliation detects only intended drift');
putenv('MEDIA_RECONCILE_MAINTENANCE=true');
try{$repair=$reconciler->repair($tenantA);}finally{putenv('MEDIA_RECONCILE_MAINTENANCE=false');}
assertTest($repair['applied']===true && $reconciler->preview($tenantA)['drift']['used_bytes']===0,'exclusive quota repair restores tenant A accounting');
$health=App::get()->service(\Brightery\Core\Operations\HealthReport::class)->snapshot();
assertTest(isset($health['assessment']['status']) && $health['framework']===\Brightery\Core\Kernel::VERSION,'read-only operations status computed from real DB');
$preflight=App::get()->service(\Brightery\Core\Operations\ReleasePreflight::class)->inspect();
assertTest(is_array($preflight['checks']) && $preflight['ready'],'preflight validates actual schema and extensions');
// v1.2: generated modules CRUD + cross-tenant isolation, disposable test schema only.
$generatorDir=sys_get_temp_dir().'/bf-int-builder-'.bin2hex(random_bytes(6));mkdir($generatorDir,0700,true);
$generator=new \Brightery\Core\Developer\ModuleScaffolder($generatorDir);
$tenantBlueprint=['name'=>'Catalog','title'=>'Catalog','scope'=>'tenant','fields'=>[
    ['name'=>'title','label'=>'Title','type'=>'text','required'=>true],
    ['name'=>'amount','label'=>'Amount','type'=>'decimal','required'=>false],
]];
$centralBlueprint=['name'=>'GlobalSettings','title'=>'Global Settings','scope'=>'central','fields'=>[
    ['name'=>'message','label'=>'Message','type'=>'text','required'=>true],
]];
foreach([$tenantBlueprint,$centralBlueprint] as $blueprint){
    $draft=$generator->plan($blueprint);$generator->apply($blueprint,$draft['digest']);
    foreach(\Brightery\Core\Database\Migrator::statements($draft['sql']) as $statement)$db->pdo()->exec($statement);
}
// v1.3 integration: relation metadata, scoped lookup, paging and additive migrations.
$lineBlueprint=['name'=>'CatalogLines','title'=>'Catalog Lines','scope'=>'tenant','fields'=>[
    ['name'=>'description','label'=>'Description','type'=>'text','required'=>true],
    ['name'=>'catalog_id','label'=>'Catalog','type'=>'relation','required'=>false,'references'=>'Catalog'],
]];
$linePlan=$generator->plan($lineBlueprint);$generator->apply($lineBlueprint,$linePlan['digest']);
foreach(\Brightery\Core\Database\Migrator::statements($linePlan['sql']) as $statement)$db->pdo()->exec($statement);
$ctx=new \Brightery\Core\Tenancy\ContextStore();
$tenants=new \Brightery\Core\Tenancy\TenantRecords($db,$ctx);
$tenantRepo=new \Brightery\Core\Developer\CrudRepository($db,$tenants,\Brightery\Core\Developer\ModuleBlueprint::normalize($tenantBlueprint));
$centralRepo=new \Brightery\Core\Developer\CrudRepository($db,$tenants,\Brightery\Core\Developer\ModuleBlueprint::normalize($centralBlueprint));
$ctx->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
$own=$tenantRepo->create(['title'=>'Tenant A secret','amount'=>'29.50']);
assertTest($own>0 && $tenantRepo->find($own)['title']==='Tenant A secret','generated tenant model can create and read');
$lines=new \Brightery\Core\Developer\CrudRepository($db,$tenants,\Brightery\Core\Developer\ModuleBlueprint::normalize($lineBlueprint),null,$generatorDir);
$linked=$lines->create(['description'=>'Line in A','catalog_id'=>(string)$own]);
assertTest($linked>0 && $lines->find($linked)['catalog_id']==$own,'v1.3 relation accepts active tenant target');
assertTest($tenantRepo->page(['search'=>'Tenant','per_page'=>1])['total']===1,'v1.3 tenant pagination applies scoped count and text search');
$extended=$lineBlueprint;$extended['fields'][]=['name'=>'memo','label'=>'Memo','type'=>'text','required'=>false];
$evolution=new \Brightery\Core\Developer\ModuleEvolver($generatorDir);
$evolutionPlan=$evolution->plan('CatalogLines',$extended);
$evolution->apply('CatalogLines',$extended,$evolutionPlan['digest']);
$offlineModules=new \Brightery\Core\Modules\ModuleManager($generatorDir.'/states.json',$generatorDir);
$offlineMigrator=new \Brightery\Core\Database\Migrator($db,$offlineModules);
$offlineApplied=$offlineMigrator->runDisabledModule('CatalogLines');
assertTest(in_array('module_CatalogLines__'.basename($evolutionPlan['migration']),$offlineApplied,true),'v1.3 disabled module migration runs before activation');

$linesExtended=new \Brightery\Core\Developer\CrudRepository($db,$tenants,\Brightery\Core\Developer\ModuleBlueprint::normalize($extended),null,$generatorDir);
assertTest(array_key_exists('memo',$linesExtended->find($linked)) && $linesExtended->find($linked)['memo']===null,'v1.3 additive migration preserves existing records');
$ctx->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantB));
assertTest($tenantRepo->find($own)===null && $tenantRepo->update($own,['title'=>'Hijack','amount'=>'3.00'])===0 && $tenantRepo->delete($own)===0,'generated CRUD forbids cross-tenant access');
try{$lines->create(['description'=>'Cross tenant relation','catalog_id'=>(string)$own]);throw new RuntimeException('Cross-tenant relation accepted');}
catch(InvalidArgumentException){assertTest($lines->page()['total']===0,'v1.3 relation excludes references from another tenant');}
assertTest($lines->find($linked)===null,'v1.3 relation record not visible outside owning tenant');
$ctx->set(new \Brightery\Core\Tenancy\TenantContext('central'));
try{$tenantRepo->listing();throw new RuntimeException('Tenant repo allowed central context');}
catch(LogicException $error){assertTest(true,'generated tenant model denies unscoped access');}
$global=$centralRepo->create(['message'=>'Central data']);
assertTest($centralRepo->find($global)['message']==='Central data' && $centralRepo->update($global,['message'=>'Updated'])===1,'generated central model CRUD works');
assertTest($centralRepo->page(['filter'=>'message','value'=>'Updated'])['total']===1 && $centralRepo->page(['search'=>'Not found'])['total']===0,'v1.3 central filter/search operate with bound values');

// v1.4: searchable relation choices, allowlisted ordering and transactional bulk operations.
$ctx->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
$ownChoices=$lines->searchRelation('catalog_id','secret',null,20,$generatorDir);
assertTest(count($ownChoices)===1 && $ownChoices[0]['id']===$own,'v1.4 relation AJAX searches authorized tenant data');
assertTest($tenantRepo->page(['sort'=>'title','direction'=>'ASC','per_page'=>10])['total']===1,'v1.4 tenant sorting uses allowlisted schema');
$extraA=$tenantRepo->create(['title'=>'Tenant A second','amount'=>'8.00']);
$ctx->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantB));
assertTest($lines->searchRelation('catalog_id','secret',null,20,$generatorDir)===[],'v1.4 relation search never returns another tenant record');
$tenantBrow=$tenantRepo->create(['title'=>'Tenant B','amount'=>'3.00']);
try{$tenantRepo->bulkDelete([$tenantBrow,$own]);throw new RuntimeException('Cross-tenant bulk deletion succeeded');}
catch(InvalidArgumentException){assertTest($tenantRepo->find($tenantBrow)!==null,'v1.4 bulk operation rolls back on cross-tenant record');}
$ctx->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
assertTest($tenantRepo->find($own)!==null && $tenantRepo->find($extraA)!==null,'v1.4 cross-tenant deletion left tenant A records untouched');
assertTest($tenantRepo->bulkDelete([$extraA])===1 && $tenantRepo->find($extraA)===null,'v1.4 authorized tenant bulk deletion succeeds');
$ctx->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantB));
assertTest($tenantRepo->bulkDelete([$tenantBrow])===1,'v1.4 tenant B may delete only own records');
$ctx->set(new \Brightery\Core\Tenancy\TenantContext('central'));
assertTest($centralRepo->page(['sort'=>'message','direction'=>'ASC'])['total']>=1,'v1.4 central ordering respects schema');
assertTest($centralRepo->bulkDelete([$global])===1 && $centralRepo->find($global)===null,'v1.4 central bulk delete succeeds');

// v1.5: test newly generated opt-in soft deletion and tenant-scoped restore.
$softDef=['name'=>'SoftDrafts','title'=>'Soft Drafts','scope'=>'tenant','soft_delete'=>true,'fields'=>[
 ['name'=>'title','label'=>'Title','type'=>'text','required'=>true,'rules'=>['min_length'=>3,'max_length'=>70]],
]];
$softPlan=$generator->plan($softDef);$generator->apply($softDef,$softPlan['digest']);
foreach(\Brightery\Core\Database\Migrator::statements($softPlan['sql']) as $statement)$db->pdo()->exec($statement);
$soft=new \Brightery\Core\Developer\CrudRepository($db,$tenants,\Brightery\Core\Developer\ModuleBlueprint::normalize($softDef));
$ctx->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
$postA=$soft->create(['title'=>'Tenant A draft']);
assertTest($soft->delete($postA)===1&&$soft->find($postA)===null&&$soft->page()['total']===0,'v1.5 soft delete hides active records');
assertTest($soft->trashed($postA)!==null&&$soft->page(['view'=>'trash'])['total']===1,'v1.5 trash list only includes deleted records');
$ctx->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantB));
assertTest($soft->trashed($postA)===null&&$soft->restore($postA)===0,'v1.5 restore cannot access foreign tenant');
$postB=$soft->create(['title'=>'Tenant B draft']);
try{$soft->bulkDelete([$postB,$postA]);throw new RuntimeException('Soft bulk cross-tenant deletion accepted');}
catch(InvalidArgumentException){assertTest($soft->find($postB)!==null,'v1.5 soft bulk operations are transactional across tenant boundary');}
$ctx->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
assertTest($soft->restore($postA)===1&&$soft->find($postA)!==null&&$soft->trashed($postA)===null,'v1.5 restore returns row to active view');
$refDef=['name'=>'DraftReviews','title'=>'Draft Reviews','scope'=>'tenant','fields'=>[['name'=>'draft_id','label'=>'Draft','type'=>'relation','required'=>true,'references'=>'SoftDrafts']]];
$refPlan=$generator->plan($refDef);$generator->apply($refDef,$refPlan['digest']);
foreach(\Brightery\Core\Database\Migrator::statements($refPlan['sql']) as $statement)$db->pdo()->exec($statement);
$refRepo=new \Brightery\Core\Developer\CrudRepository($db,$tenants,\Brightery\Core\Developer\ModuleBlueprint::normalize($refDef),null,$generatorDir);
assertTest($refRepo->create(['draft_id'=>(string)$postA])>0,'v1.5 active soft-delete reference can be selected');
$soft->delete($postA);
assertTest($refRepo->searchRelation('draft_id','',null,20,$generatorDir)===[],'v1.5 deleted records excluded from relationship selector');
try{$refRepo->create(['draft_id'=>(string)$postA]);throw new RuntimeException('Deleted related row accepted');}
catch(InvalidArgumentException){assertTest(true,'v1.5 deleted relationship reference rejected');}
$db->pdo()->exec('DROP TABLE `bf_gen_draft_reviews`');$db->pdo()->exec('DROP TABLE `bf_gen_soft_drafts`');

// v1.6: real join-table writes, tenant isolation, concurrent-safe sync, and additive join-table evolution.
$tagDefinition16=['name'=>'ScopedTags','title'=>'Scoped Tags','scope'=>'tenant','soft_delete'=>true,'fields'=>[['name'=>'title','label'=>'Title','type'=>'text','required'=>true]]];
$tagPlan16=$generator->plan($tagDefinition16);$generator->apply($tagDefinition16,$tagPlan16['digest']);
foreach(\Brightery\Core\Database\Migrator::statements($tagPlan16['sql']) as $statement)$db->pdo()->exec($statement);
$postDefinition16=['name'=>'ScopedPosts','title'=>'Scoped Posts','scope'=>'tenant','fields'=>[
 ['name'=>'title','label'=>'Title','type'=>'text','required'=>true],
 ['name'=>'tags','label'=>'Tags','type'=>'many_to_many','required'=>false,'references'=>'ScopedTags'],
]];
$postPlan16=$generator->plan($postDefinition16);$generator->apply($postDefinition16,$postPlan16['digest']);
foreach(\Brightery\Core\Database\Migrator::statements($postPlan16['sql']) as $statement)$db->pdo()->exec($statement);
$tagRepo16=new \Brightery\Core\Developer\CrudRepository($db,$tenants,\Brightery\Core\Developer\ModuleBlueprint::normalize($tagDefinition16),null,$generatorDir);
$postRepo16=new \Brightery\Core\Developer\CrudRepository($db,$tenants,\Brightery\Core\Developer\ModuleBlueprint::normalize($postDefinition16),null,$generatorDir);
$ctx->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
$tagA16=$tagRepo16->create(['title'=>'Tenant A tag']);$tagA216=$tagRepo16->create(['title'=>'Tenant A secondary']);
$postA16=$postRepo16->create(['title'=>'Tenant A post','tags'=>[(string)$tagA16,(string)$tagA216]]);
assertTest(count($postRepo16->relatedChoices('tags',$postA16))===2,'v1.6 many-to-many choices stored and resolved in same tenant');
assertTest($postRepo16->page()['total']===1,'v1.6 virtual join fields excluded from SQL page fields');
$ctx->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantB));
$tagB16=$tagRepo16->create(['title'=>'Tenant B tag']);
try{$postRepo16->create(['title'=>'Foreign target','tags'=>[$tagA16]]);throw new RuntimeException('Foreign tag attached');}
catch(InvalidArgumentException){assertTest($postRepo16->page()['total']===0,'v1.6 cross-tenant target rejected atomically');}
try{$postRepo16->relatedChoices('tags',$postA16);throw new RuntimeException('Foreign source relationships exposed');}
catch(InvalidArgumentException){assertTest(true,'v1.6 cross-tenant relation reads denied');}
$ctx->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
try{$postRepo16->update($postA16,['tags'=>[$tagB16]],true);throw new RuntimeException('Foreign relation update succeeded');}
catch(InvalidArgumentException){assertTest(count($postRepo16->relatedChoices('tags',$postA16))===2,'v1.6 invalid update rolls back existing relationship set');}
assertTest($postRepo16->update($postA16,['tags'=>[$tagA16]],true)===1 && count($postRepo16->relatedChoices('tags',$postA16))===1,'v1.6 authorized PATCH replaces relationship selection');
assertTest($postRepo16->update($postA16,['tags'=>[]],true)===1 && $postRepo16->relatedChoices('tags',$postA16)===[],'v1.6 empty relationship selection detaches all rows');
assertTest(count($postRepo16->csvRows())===2,'v1.6 bound CSV export includes header and current tenant row');
$tagRepo16->delete($tagA16);
try{$postRepo16->update($postA16,['tags'=>[$tagA16]],true);throw new RuntimeException('Trashed tag attached');}
catch(InvalidArgumentException){assertTest($postRepo16->relatedChoices('tags',$postA16)===[],'v1.6 trashed related record rejected');}
$extended16=$postDefinition16;$extended16['fields'][]=['name'=>'secondary_tags','label'=>'Secondary','type'=>'many_to_many','required'=>false,'references'=>'ScopedTags'];
$evolution16=new \Brightery\Core\Developer\ModuleEvolver($generatorDir);
$preview16=$evolution16->plan('ScopedPosts',$extended16);
$evolution16->apply('ScopedPosts',$extended16,$preview16['digest']);
foreach(\Brightery\Core\Database\Migrator::statements($preview16['sql']) as $statement)$db->pdo()->exec($statement);
$extendedRepo16=new \Brightery\Core\Developer\CrudRepository($db,$tenants,\Brightery\Core\Developer\ModuleBlueprint::normalize($extended16),null,$generatorDir);
assertTest($extendedRepo16->update($postA16,['secondary_tags'=>[$tagA216]],true)===1 && count($extendedRepo16->relatedChoices('secondary_tags',$postA16))===1,'v1.6 append-only join field migration works on existing records');
$ctx->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantB));
assertTest($extendedRepo16->update($postA16,['secondary_tags'=>[$tagB16]],true)===0,'v1.6 source ownership enforced on relationship writes');
$ctx->set(new \Brightery\Core\Tenancy\TenantContext('tenant',$tenantA));
$tagRepo16->delete($tagA216);
assertTest($extendedRepo16->relatedChoices('secondary_tags',$postA16)===[],'v1.6 soft-deleted relation target hidden in choices');
foreach(['tags','secondary_tags'] as $relationship16){$pivot16=\Brightery\Core\Developer\ModuleBlueprint::pivotFor('ScopedPosts',$relationship16);$db->pdo()->exec('DROP TABLE `'.$pivot16.'`');}
$db->pdo()->exec('DROP TABLE `bf_gen_scoped_posts`');$db->pdo()->exec('DROP TABLE `bf_gen_scoped_tags`');

$db->pdo()->exec('DROP TABLE `bf_gen_catalog_lines`');$db->pdo()->exec('DROP TABLE `bf_gen_catalog`');$db->pdo()->exec('DROP TABLE `bf_gen_global_settings`');
$ctx->clear();
$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($generatorDir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($files as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($generatorDir);
echo "MYSQL INTEGRATION TESTS PASSED (through v1.6; disposable database retained for inspection)\n";
