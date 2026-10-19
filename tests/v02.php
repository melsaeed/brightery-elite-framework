<?php
declare(strict_types=1);
// Included by tests/run.php after bootstrapping an isolated request context.
use Brightery\Core\{Container,Database,Kernel};
use Brightery\Core\Contracts\TenantResolverInterface;
use Brightery\Core\Http\{Request,Response,Router};
use Brightery\Core\Database\Migrator;
use Brightery\Core\Modules\ModuleManager;
use Brightery\Core\Tenancy\{TenantContext,ContextStore,HostTenantResolver,TenantRecords,TenantCache};
use Brightery\Core\Ui\{AssetManager,IslandRenderer,CoreIslands};

$di=new Container();
class V02Value {public function __construct(public readonly string $word='fresh'){} }
$di->bind(V02Value::class,static fn()=>new V02Value(),false);
check($di->get(V02Value::class)!==$di->get(V02Value::class),'transient binding');
$di->scoped('v02.scope',static fn()=>new V02Value());
$first=$di->get('v02.scope');check($first===$di->get('v02.scope'),'request scoped binding reused');
$di->resetScope();check($first!==$di->get('v02.scope'),'scope reset releases service');
$di->alias('v02.alias',V02Value::class);check($di->get('v02.alias') instanceof V02Value,'service alias');
$di->bind('v02.cycle',static fn(Container $c)=>$c->get('v02.cycle'));
try{$di->get('v02.cycle');check(false,'cycle should fail');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'Circular dependency'),'cycle detection');}
$di->bind(V02Value::class,static fn()=>new V02Value('original'));
$di->extend(V02Value::class,static fn()=>new V02Value('decorated'));
check($di->get(V02Value::class)->word==='decorated','decorator application');

$router=new Router();$calls=[];
$router->group('/api',static function(Router $route)use(&$calls){
    $route->get('/products/{id}',static fn(Request $request,array $params)=>Response::json(['id'=>$params['id']]),[], 'product.show');
},[static function(Request $r,callable $next)use(&$calls):Response {$calls[]='group';return $next();}]);
check($router->url('product.show',['id'=>42])==='/api/products/42','named route URL generation');
check($router->dispatch(new Request('GET','/api/products/42'))->body==='{"id":"42"}'&&$calls===['group'],'group middleware with named parameters');

$temp=sys_get_temp_dir().'/bf-test-'.bin2hex(random_bytes(6));
mkdir($temp.'/modules/System',0700,true);mkdir($temp.'/modules/Alpha',0700,true);mkdir($temp.'/modules/Beta',0700,true);
file_put_contents($temp.'/modules/System/module.json',json_encode(['name'=>'System','version'=>'0.2.0','required'=>true]));
file_put_contents($temp.'/modules/Alpha/module.json',json_encode(['name'=>'Alpha','version'=>'0.2.1','dependencies'=>['System'=>'^0.2.0']]));
file_put_contents($temp.'/modules/Beta/module.json',json_encode(['name'=>'Beta','version'=>'0.2.2','dependencies'=>['Alpha'=>'>=0.2.0']]));
$mm=new ModuleManager($temp.'/private/state.json',$temp.'/modules');
check(array_keys($mm->ordered(false))===['System','Alpha','Beta'],'topological dependency order');
try{$mm->setEnabled('Beta',true);check(false,'incomplete module graph accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'requires enabled Alpha'),'enabling checks dependencies');}
$mm->setEnabled('Alpha',true);$mm->setEnabled('Beta',true);
check($mm->enabled('Alpha')&&$mm->enabled('Beta'),'module activation persists');
try{$mm->setEnabled('Alpha',false);check(false,'disabled dependency accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'requires enabled Alpha'),'deactivation blocks dependents');}
check($mm->enabled('Alpha'),'failed module deactivation left state unchanged');
file_put_contents($temp.'/modules/Alpha/module.json',json_encode(['name'=>'Alpha','version'=>'0.2.1','dependencies'=>['Beta'=>'*']]));
try{(new ModuleManager($temp.'/private/state.json',$temp.'/modules'))->validate();check(false,'module cycle accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'cycle'),'module cycle detection');}
foreach(glob($temp.'/modules/*/module.json') as $file)unlink($file);
foreach(glob($temp.'/modules/*') as $dir)rmdir($dir);
foreach(glob($temp.'/private/*') as $file)unlink($file);
rmdir($temp.'/private');rmdir($temp.'/modules');rmdir($temp);

$sql="-- begin\nINSERT INTO x(note) VALUES('a;b'); /* ignored ; */\n# ignored\n INSERT INTO y(v) VALUES('two'';three');";
check(count(Migrator::statements($sql))===2,'migration SQL respects strings and comments');
check(str_contains(Migrator::statements($sql)[1],"two'';three"),'migration quote escaping');

$records=new TenantRecords(new Database(),new ContextStore());
try{$records->all('private_records');check(false,'tenantless fetch allowed');}catch(LogicException $e){check(true,'tenantless records rejected');}
$contextStore=new ContextStore();$contextStore->set(new TenantContext('tenant',9,'acme','acme.example.com'));
try{(new TenantRecords(new Database(),$contextStore))->all('tenant_notes;DROP');check(false,'unsafe table allowed');}catch(InvalidArgumentException $e){check(true,'tenant identifier validation');}
$tenantStore=new ContextStore();$tenantStore->set(new TenantContext('tenant',4));
$cache=new \Brightery\Core\Cache\FileCache(base_path('storage/cache'));
$tenantCache=new TenantCache($cache,$tenantStore);$tenantCache->put('v02:test','company4',10);
$tenantStore->set(new TenantContext('tenant',5));
check($tenantCache->get('v02:test')===null,'tenant cache keys are isolated');
$tenantStore->set(new TenantContext('tenant',4));
check($tenantCache->get('v02:test')==='company4','tenant cache resolves correct company');
$tenantCache->forget('v02:test');
check(HostTenantResolver::hostname('Acme.Example.COM:443')==='acme.example.com','host canonicalization');
check(HostTenantResolver::hostname('bad..example.com')==='','invalid host rejection');

$ui=new IslandRenderer();CoreIslands::install($ui);
$rendered=$ui->render('tags',['name'=>'topics','label'=>'<script>x</script>','values'=>['marketing']]);
check(!str_contains($rendered,'<script>'),'island renderer escapes labels');
check(str_contains($rendered,'data-bf-island="tags"'),'server rendered island registration');
check(str_starts_with((new AssetManager())->url('js/islands.js'),'/assets/build/islands.'),'fingerprinted island asset');

$prevMode=getenv('TENANCY_MODE');$prevTrusted=getenv('TRUSTED_HOSTS');$prevCentral=getenv('TENANCY_CONTROL_HOST');
putenv('TENANCY_MODE=multi');putenv('TENANCY_CONTROL_HOST=admin.example.com');putenv('TRUSTED_HOSTS=admin.example.com,localhost');
$kernel->container->bind(TenantResolverInterface::class,static fn()=>new class implements TenantResolverInterface {
    public function resolve(Request $r):?TenantContext {
        return match(HostTenantResolver::hostname((string)($r->server['HTTP_HOST']??''))){
            'admin.example.com'=>new TenantContext('central'),
            'acme.example.com'=>new TenantContext('tenant',9,'acme','acme.example.com'),
            default=>null,
        };
    }
});
$kernel->router->get('/__v02-context',static fn(Request $request):Response=>Response::json(['mode'=>\Brightery\Core\App::get()->service(ContextStore::class)->current()->mode]));
check($kernel->handle(new Request('GET','/__v02-context',server:['HTTP_HOST'=>'admin.example.com']))->body==='{"mode":"central"}','central host context');
check($kernel->handle(new Request('GET','/__v02-context',server:['HTTP_HOST'=>'acme.example.com']))->body==='{"mode":"tenant"}','tenant context resolution');
check($kernel->handle(new Request('GET','/admin',server:['HTTP_HOST'=>'acme.example.com']))->status===404,'global admin blocked on tenant host');
check($kernel->handle(new Request('GET','/__v02-context',server:['HTTP_HOST'=>'unknown.example.com']))->status===404,'unknown tenant host fails closed');
try{$kernel->service(ContextStore::class)->current();check(false,'tenant context leaked');}catch(LogicException $e){check(true,'tenant context cleared between requests');}
foreach(['TENANCY_MODE'=>$prevMode,'TRUSTED_HOSTS'=>$prevTrusted,'TENANCY_CONTROL_HOST'=>$prevCentral] as $name=>$old){if($old===false)putenv($name);else putenv($name.'='.$old);}
