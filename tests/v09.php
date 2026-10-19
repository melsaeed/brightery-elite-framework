<?php
declare(strict_types=1);
use Brightery\Core\Operations\{WorkerRegistry,MediaReconciler,HealthReport,ReleasePreflight,ReleaseCoordinator};
use Brightery\Core\Database\Migrator;
check(version_compare(\Brightery\Core\Kernel::VERSION,'0.9.0','>='),'v0.9 release identifier');
$workerId=WorkerRegistry::workerId();
check(!!preg_match('/^[a-f0-9]{32}$/',$workerId),'worker identifiers are unpredictable hex tokens');
WorkerRegistry::validateId($workerId);
try{WorkerRegistry::validateId('../secret');check(false,'worker ID traversal accepted');}
catch(RuntimeException){check(true,'worker identifiers reject unsafe input');}
check(HealthReport::classify(['pending'=>1,'failed'=>0],1200,0)['status']==='critical','stalled queue raises critical status');
check(HealthReport::classify(['pending'=>0,'failed'=>2],null,1)['status']==='warning','failed jobs raise warning status');
check(HealthReport::classify(['pending'=>0,'failed'=>0],null,0)['status']==='healthy','idle queue does not require active workers');
check(ReleasePreflight::appKeyValid(bin2hex(random_bytes(32))),'nontrivial APP_KEY format accepted');
check(!ReleasePreflight::appKeyValid(str_repeat('0',64))&&!ReleasePreflight::appKeyValid('bad-key'),'placeholder APP_KEY rejected');
check(!ReleasePreflight::trustedHostsValid('example.com,*')&&ReleasePreflight::trustedHostsValid('example.com,api.example.com'),'wildcard trusted host rejected');
check(MediaReconciler::safeKey(str_repeat('a',64))&&!MediaReconciler::safeKey('../'.str_repeat('a',64)),'media storage keys are validated');
check(MediaReconciler::difference(['used_bytes'=>12,'original_count'=>3,'variant_count'=>1],['used_bytes'=>8,'original_count'=>2,'variant_count'=>2])===['used_bytes'=>-4,'original_count'=>-1,'variant_count'=>1],'quota drift calculated with signed deltas');
try{MediaReconciler::scope(-1);check(false,'negative scope accepted');}
catch(RuntimeException){check(true,'invalid scope rejected');}
$reconcile=new MediaReconciler($kernel->service(\Brightery\Core\Database::class),base_path('storage/private/media'));
putenv('MEDIA_RECONCILE_MAINTENANCE=false');
try{$reconcile->repair(0);check(false,'unapproved reconciliation repair executed');}
catch(RuntimeException){check(true,'quota repair is maintenance guarded');}
$coordinator=new ReleaseCoordinator($kernel->service(ReleasePreflight::class),$kernel->service(Migrator::class),base_path('storage/private'));
check($coordinator->current()===null || is_array($coordinator->current()),'release marker read is safe without finalization');
check(count(Migrator::statements(file_get_contents(base_path('database/migrations/008_operations_reliability.sql'))))===1,'worker migration contains valid SQL');
check(isset($kernel->router->routes()[0]),'existing module routes remain registered');
