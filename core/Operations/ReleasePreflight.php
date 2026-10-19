<?php
declare(strict_types=1);
namespace Brightery\Core\Operations;
use Brightery\Core\Database\Migrator;
use Brightery\Core\Modules\ModuleManager;

/** No side effects beyond Migrator's schema_migrations bootstrap on DB check. */
final class ReleasePreflight {
    public function __construct(private readonly ModuleManager $modules,private readonly Migrator $migrator){}
    public static function appKeyValid(string $key):bool {
        return preg_match('/^[0-9a-fA-F]{64}$/D',$key)===1&&!preg_match('/^(.)\1{63}$/D',$key)&&$key!==str_repeat('0',64);
    }
    public static function trustedHostsValid(string $hosts):bool {
        $parts=array_filter(array_map('trim',explode(',',$hosts)),'strlen');
        if(!$parts)return false;
        foreach($parts as $host)if(strlen($host)>253||!preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/D',$host)||str_contains($host,'..'))return false;
        return true;
    }
    public function inspect(bool $database=true):array {
        $checks=[];
        $add=static function(string $name,string $status,string $detail)use(&$checks):void {$checks[]=['name'=>$name,'status'=>$status,'detail'=>$detail];};
        $add('php','pass' ,PHP_VERSION_ID>=80400?'PHP 8.4+ supported':'PHP below 8.4');
        if(PHP_VERSION_ID<80400)$checks[count($checks)-1]['status']='fail';
        foreach(['pdo_mysql','fileinfo','sodium'] as $extension)$add('extension.'.$extension,extension_loaded($extension)?'pass':'fail',extension_loaded($extension)?'available':'missing');
        $production=(string)env('APP_ENV','local')==='production';
        $key=(string)env('APP_KEY','');
        $add('app_key',self::appKeyValid($key)?'pass':($production?'fail':'warn'),'configured 64-character hexadecimal key required');
        $add('debug',!$production||env('APP_DEBUG','false')!=='true'?'pass':'fail','APP_DEBUG must be disabled in production');
        $add('session_tls',!$production||env('SESSION_SECURE','false')==='true'?'pass':'fail','Production sessions require TLS');
        $add('trusted_hosts',self::trustedHostsValid((string)env('TRUSTED_HOSTS',''))?'pass':'fail','Use explicit trusted hostnames');
        if(env('TENANCY_MODE','single')==='multi'){
            $control=(string)env('TENANCY_CONTROL_HOST','');
            $hosts=array_map('trim',explode(',',(string)env('TRUSTED_HOSTS','')));
            $add('tenancy_control_host',$control!==''&&in_array($control,$hosts,true)?'pass':'fail','Control host must be explicitly trusted');
        }
        foreach(['storage/cache','storage/logs','storage/private'] as $path){
            $full=base_path($path);$add('writable.'.$path,is_dir($full)&&is_writable($full)?'pass':'fail','Required runtime directory');
        }
        try{$ordered=$this->modules->ordered();$add('module_graph','pass',count($ordered).' enabled modules validated');}
        catch(\Throwable $e){$add('module_graph','fail',get_class($e));}
        if($database){
            try{$status=$this->migrator->status();$pending=count(array_filter($status,static fn($v)=>$v==='pending'));
                $add('migrations',$pending===0?'pass':'warn',$pending.' pending migration(s)');}
            catch(\Throwable $e){$add('database','fail',get_class($e));}
        }
        $fail=count(array_filter($checks,static fn($c)=>$c['status']==='fail'));
        $warnings=count(array_filter($checks,static fn($c)=>$c['status']==='warn'));
        return ['ready'=>$fail===0,'failures'=>$fail,'warnings'=>$warnings,'checks'=>$checks];
    }
}
