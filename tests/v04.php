<?php
declare(strict_types=1);
// v0.4 DB-independent safety and contract tests; integration fixtures in integration_mysql.php.
use Brightery\Core\Jobs\{Registry,Queue};
use Brightery\Core\Scheduler\Schedule;
use Brightery\Core\Api\{TokenService,ApiIdentity};
use Brightery\Core\Audit\AuditTrail;
use Brightery\Core\Mfa\Totp;
use Brightery\Core\{Database,Kernel,Container};
use Brightery\Core\Tenancy\ContextStore;
use Brightery\Core\Database\Migrator;
use Brightery\Core\Http\Request;

check(version_compare(Kernel::VERSION,'0.4.0','>='),'v0.4 core compatibility');
$registered=new Registry();$called=0;
$registered->register('demo.simple',static function(array $data) use(&$called):void{$called=(int)$data['value'];});
$registered->run('demo.simple',['value'=>42]);check($called===42,'trusted job registry');
try{$registered->register('demo.simple',static fn()=>null);check(false,'duplicate handler accepted');}
catch(RuntimeException){check(true,'duplicate handler rejected');}
try{$registered->register('unsafe\\php',static fn()=>null);check(false,'unsafe job name accepted');}
catch(RuntimeException){check(true,'unsafe job name rejected');}
$registryQueue=new Queue(new Database(),new ContextStore(),$registered);
$scheduler=new Schedule(new Database(),$registryQueue,$registered);
$scheduler->everyMinutes('demo.periodic','demo.simple',5);
$scheduler->dailyUtc('demo.daily','demo.simple',12,30);
check(count($scheduler->definitions())===2,'interval and UTC daily schedule declarations');
try{$scheduler->everyMinutes('demo.periodic','demo.simple',5);check(false,'duplicate scheduler declaration accepted');}
catch(RuntimeException){check(true,'duplicate scheduler declaration rejected');}
try{$scheduler->everyMinutes('demo.invalid','unknown.handler',1);check(false,'unregistered job scheduled');}
catch(RuntimeException){check(true,'unregistered scheduled handler rejected');}
check(TokenService::validScopes(['profile:read'])&&!TokenService::validScopes(['all'])&&!TokenService::validScopes(['profile:read','profile:read']),'API scopes deny wildcard and duplicates');
$token='bfpat_25_'.bin2hex(random_bytes(32));
check(TokenService::parse($token)['id']===25&&TokenService::parse('bfpat_0_'.str_repeat('a',64))===null,'personal token syntax');
check(TokenService::parse($token.'Z')===null&&TokenService::parse('Bearer '.$token)===null,'malformed API tokens rejected');
$sanitized=AuditTrail::sanitize(['password'=>'secret','token'=>'secret','nested'=>['access_token'=>'hidden','count'=>1],'note'=>'hello']);
check($sanitized['password']==='[REDACTED]'&&$sanitized['nested']['access_token']==='[REDACTED]'&&$sanitized['note']==='hello','audit metadata redaction');
$raw=hex2bin('3132333435363738393031323334353637383930');
check(Totp::code($raw,1)==='287082','TOTP RFC 6238 six-digit reference vector');
check(Totp::decodeBase32(Totp::encodeBase32($raw))===$raw,'TOTP base32 codec roundtrip');
check(count(Migrator::statements(file_get_contents(base_path('database/migrations/004_operations.sql'))))===5,'v0.4 additive SQL migration parser');
$names=array_column($kernel->router->routes(),'path');
check(in_array('/mfa',$names,true)&&in_array('/account/mfa',$names,true),'MFA authentication and account routes');
check(in_array('/api/v1/me',$names,true)&&in_array('/api/v1/workspace',$names,true),'scoped JSON API routes');
check(in_array('/admin/operations',$names,true),'operations dashboard route');
$kernel->container->bind(\Brightery\Core\Contracts\TenantResolverInterface::class, \Brightery\Core\Tenancy\HostTenantResolver::class);
check($kernel->handle(new Request('GET','/api/v1/me',server:['HTTP_HOST'=>'localhost']))->status===401,'API rejects missing bearer token');
check($kernel->handle(new Request('POST','/mfa',server:['HTTP_HOST'=>'localhost']))->status===419,'MFA challenge write CSRF protected');
check($kernel->container->get(Container::class)===$kernel->container,'DI self binding available to queue workers');
check($kernel->service(Registry::class)->has('system.invites.prune'),'module hooks register queue handlers before scheduler');
