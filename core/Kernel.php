<?php
declare(strict_types=1);
namespace Brightery\Core;
use Brightery\Core\Http\{Router,Request,Response};
use Brightery\Core\Cache\{CacheInterface,FileCache,RedisCache};
use Brightery\Core\Contracts\TenantResolverInterface;
use Brightery\Core\Tenancy\{ContextStore,HostTenantResolver};
use Brightery\Core\I18n\Translator;
use Brightery\Core\Ui\{IslandRenderer,CoreIslands};
use Brightery\Core\Modules\ModuleManager;
use Brightery\Core\Events\Hooks;
use Brightery\Core\Observability\{LoggerInterface,JsonLogger,RequestTelemetry};
use Throwable;

final class Kernel {
    public readonly Container $container;
    public readonly Router $router;
    public const VERSION='1.6.1';
    public function __construct(){
        $this->container=new Container();$this->router=new Router();
        $this->container->instance(self::class,$this);
        $this->container->instance(Container::class,$this->container);
        $this->container->scoped(\Brightery\Core\Api\ApiIdentity::class,\Brightery\Core\Api\ApiIdentity::class);
        $this->container->instance(Router::class,$this->router);
        $this->container->bind(LoggerInterface::class,fn()=>new JsonLogger(base_path('storage/logs/requests.jsonl'),(string)env('LOG_LEVEL','info')));
        $this->container->bind(CacheInterface::class,function(){
            if((string)env('CACHE_DRIVER','file')==='redis'&&class_exists('Redis')){
                $redis=new \Redis();$redis->connect((string)env('REDIS_HOST','127.0.0.1'),(int)env('REDIS_PORT',6379),1.5);
                return new RedisCache($redis);
            }
            return new FileCache(base_path('storage/cache'));
        });
        $this->container->bind(RateLimiter::class,fn(Container $c)=>new RateLimiter($c->get(CacheInterface::class)));
        $this->container->scoped(\Brightery\Core\Auth\SessionRegistry::class,\Brightery\Core\Auth\SessionRegistry::class);
        $this->container->bind(Auth::class,fn(Container $c)=>new Auth($c->get(Database::class),$c->get(Security::class)));
        $this->container->bind(Translator::class,fn()=>new Translator((string)env('APP_LOCALE','en')));
        $this->container->bind(TenantResolverInterface::class,HostTenantResolver::class);
        $this->container->bind(\Brightery\Core\Media\Scan\ScannerInterface::class, static function(): \Brightery\Core\Media\Scan\ScannerInterface {
            $driver=(string)env('MEDIA_SCAN_DRIVER','disabled');
            return match($driver){
                'disabled'=>new \Brightery\Core\Media\Scan\DisabledScanner(),
                'clamav'=>new \Brightery\Core\Media\Scan\ClamAvScanner((string)env('CLAMAV_SOCKET','/run/clamav/clamd.ctl')),
                default=>throw new \RuntimeException('Unknown antivirus scanner driver')
            };
        });
        CoreIslands::install($this->container->get(IslandRenderer::class));
        App::set($this);
        $this->container->get(ModuleManager::class)->boot($this->container,$this->container->get(Hooks::class));
        // App services win over module implementations, without modifying core/ files.
        $overrides=base_path('app/overrides.php');
        if(is_file($overrides)){
            $configure=require $overrides;
            if(!is_callable($configure))throw new \RuntimeException('Invalid app/overrides.php');
            $configure($this->container);
        }
        $this->container->get(ModuleManager::class)->register($this->router);
    }
    public function service(string $id):object {return $this->container->get($id);}
    public function handle(Request $request):Response {
        $security=$this->service(Security::class);
        $store=$this->service(ContextStore::class);
        $this->container->resetScope();$store->clear();$security->resetRequest();
        $telemetry=new RequestTelemetry($this->service(LoggerInterface::class));
        $exceptionClass=null;
        try {
            if((string)env('APP_ENV')==='production' && \Brightery\Core\Operations\ProductionReadiness::check()!==[])$response=Response::html('Service configuration unavailable',503);
            elseif(!$security->validateHost($request))$response=Response::html('Invalid host',400);
            elseif(!in_array($request->method,['GET','HEAD','POST','PUT','PATCH','DELETE'],true))$response=Response::html('Method not allowed',405);
            else {
                $context=$this->service(TenantResolverInterface::class)->resolve($request);
                if($context===null)$response=Response::html('Unknown tenant',404);
                elseif($context->isTenant()&&preg_match('~^/admin(?:/|$)~',$request->path))$response=Response::html('Central administration is unavailable on tenant domains',404);
                else {
                    $store->set($context);
                    $isApi=str_starts_with($request->path,'/api/v1/');
                    // Bearer credentials are checked globally before any API routes; no unguarded POST may bypass CSRF.
                    if($isApi && !$this->service(\Brightery\Core\Api\ApiIdentity::class)->authenticate($request,$context))$response=Response::json(['error'=>'unauthorized'],401);
                    elseif($isApi && !$this->service(\Brightery\Core\RateBuckets::class)->hit($context->isTenant()?$context->requireTenantId():null,'api.authenticated',(string)$this->service(\Brightery\Core\Api\ApiIdentity::class)->user()['id'],120,60))$response=Response::json(['error'=>'rate_limited'],429);
                    elseif(!$isApi&&!in_array($request->method,['GET','HEAD'],true)&&!$security->verifyCsrf($request))$response=Response::html('Invalid CSRF token',419);
                    else {
                        if(str_starts_with($request->path,'/admin'))$security->startSession();
                        $locale=$_SESSION['locale']??(string)env('APP_LOCALE','en');
                        $this->service(Translator::class)->setLocale($locale);
                        $this->service(Hooks::class)->emit('http.request',$request);
                        $response=$this->router->dispatch($request);
                        $response=$this->service(Hooks::class)->apply('http.response',$response,$request);
                    }
                }
            }
        }catch(Throwable $error){
            $exceptionClass=get_class($error);
            $debug=(string)env('APP_DEBUG','false')==='true'&&(string)env('APP_ENV','production')==='local';
            $response=Response::html($debug?'<h1>Error</h1><pre>'.esc($error->getMessage()).'</pre>':'Internal server error',500);
        }finally{
            $store->clear();$this->container->resetScope();
        }
        $response->headers=array_merge($security->headers(),$response->headers);
        $response->headers['X-Request-ID']=$telemetry->id;
        try{$telemetry->finish($request->method,$request->path,$response->status,$exceptionClass);}
        catch(Throwable){/* Log failure must never expose secrets or break HTTP responses. */}
        if(str_starts_with($request->path,'/admin')||str_starts_with($request->path,'/workspace')||str_starts_with($request->path,'/invite/')||str_starts_with($request->path,'/account/')||str_starts_with($request->path,'/mfa')||str_starts_with($request->path,'/verify-email/')||str_starts_with($request->path,'/reset-password/')||$request->path==='/forgot-password'||str_starts_with($request->path,'/api/'))$response->headers['Cache-Control']='private, no-store';
        return $response;
    }
}
