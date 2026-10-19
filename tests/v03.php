<?php
declare(strict_types=1);
// Included from tests/run.php; tests that need a database are in integration_mysql.php.
use Brightery\Core\Contracts\TenantResolverInterface;
use Brightery\Core\Http\{Request,Response};
use Brightery\Core\Tenancy\{TenantPolicy,TenantInvitations,TenantContext,ContextStore,TenantAccess};
use Brightery\Core\Database\Migrator;
use Brightery\Core\Tenancy\HostTenantResolver;

check(TenantPolicy::allows('viewer','content.read')&&!TenantPolicy::allows('viewer','content.write'),'viewer is read-only');
check(TenantPolicy::allows('editor','content.write')&&!TenantPolicy::allows('editor','invites.create'),'editor cannot invite');
check(TenantPolicy::allows('admin','members.manage')&&!TenantPolicy::mayGrant('admin','admin'),'admin cannot elevate admins');
check(TenantPolicy::mayGrant('owner','owner')&&TenantPolicy::mayChange('owner','owner','viewer'),'owner can delegate roles');
check(!TenantPolicy::mayChange('admin','owner','viewer')&&!TenantPolicy::mayChange('admin','admin','editor'),'admins cannot demote privileged users');
check(!TenantPolicy::allows('root','workspace.view')&&!TenantPolicy::validRole('superadmin'),'unknown tenant roles fail closed');
check(TenantInvitations::validToken(str_repeat('a',64))&&!TenantInvitations::validToken(str_repeat('g',64))&&!TenantInvitations::validToken('not-token'),'invitation token shape strict');
check(count(Migrator::statements(file_get_contents(base_path('database/migrations/003_tenant_invitations.sql'))))===1,'invitation migration SQL parser');
$names=array_column($kernel->router->routes(),'path');
check(in_array('/workspace/members',$names,true)&&in_array('/invite/{token}',$names,true),'tenant-only and invitation routes registered');
$oldMode=getenv('TENANCY_MODE');$oldHosts=getenv('TRUSTED_HOSTS');$oldControl=getenv('TENANCY_CONTROL_HOST');
putenv('TENANCY_MODE=multi');putenv('TENANCY_CONTROL_HOST=admin.example.com');putenv('TRUSTED_HOSTS=admin.example.com,localhost');
$kernel->container->bind(TenantResolverInterface::class,static fn()=>new class implements TenantResolverInterface {
    public function resolve(Request $request):?TenantContext {
        return match(HostTenantResolver::hostname((string)($request->server['HTTP_HOST']??''))){
            'admin.example.com'=>new TenantContext('central'),
            'testco.example.com'=>new TenantContext('tenant',20,'testco','testco.example.com'),
            default=>null,
        };
    }
});
check($kernel->handle(new Request('GET','/workspace',server:['HTTP_HOST'=>'admin.example.com']))->status===404,'central host cannot open tenant workspace');
$guest=$kernel->handle(new Request('GET','/workspace',server:['HTTP_HOST'=>'testco.example.com']));
check($guest->status===303&&($guest->headers['Location']??'')==='/workspace/login','unauthenticated tenant workspace redirects to tenant login');
$page=$kernel->handle(new Request('GET','/workspace/login',server:['HTTP_HOST'=>'testco.example.com']));
check($page->status===200&&str_contains($page->body,'/workspace/login'),'tenant login page renders');
check(($page->headers['Cache-Control']??'')==='private, no-store','tenant sessions disallow response caching');
check($kernel->handle(new Request('GET','/invite/bogus',server:['HTTP_HOST'=>'testco.example.com']))->status===404,'malformed invitation is rejected without database');
check($kernel->handle(new Request('GET','/workspace',server:['HTTP_HOST'=>'nobody.example.com']))->status===404,'unknown workspace host rejected');
try{$kernel->service(ContextStore::class)->current();check(false,'context remained after tenant request');}
catch(LogicException){check(true,'tenant context cleared after workspace request');}
foreach(['TENANCY_MODE'=>$oldMode,'TRUSTED_HOSTS'=>$oldHosts,'TENANCY_CONTROL_HOST'=>$oldControl] as $name=>$value){if($value===false)putenv($name);else putenv($name.'='.$value);}
