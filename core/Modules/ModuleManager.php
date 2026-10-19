<?php
declare(strict_types=1);
namespace Brightery\Core\Modules;
use Brightery\Core\Container;
use Brightery\Core\Contracts\ServiceProviderInterface;
use Brightery\Core\Events\Hooks;
use Brightery\Core\Http\Router;
use RuntimeException;

/** Trusted-module registry; validates manifests and dependency DAG before executing module PHP. */
final class ModuleManager {
    private readonly string $stateFile;
    private ?array $catalogue=null;
    public const API_VERSION='0.2.0';
    public function __construct(?string $stateFile=null,?string $modulesDirectory=null){
        $this->stateFile=$stateFile??base_path('storage/private/modules.json');
        $this->modulesDirectory=$modulesDirectory??base_path('modules');
    }
    private readonly string $modulesDirectory;
    public function available():array {
        if($this->catalogue!==null)return $this->catalogue;
        $items=[];
        foreach(glob($this->modulesDirectory.'/*/module.json')?:[] as $file){
            $name=basename(dirname($file));
            if(!preg_match('/^[A-Z][A-Za-z0-9]*$/D',$name))throw new RuntimeException('Invalid module directory name: '.$name);
            if(is_link(dirname($file))||is_link($file))throw new RuntimeException('Module symlinks are not permitted: '.$name);
            $data=json_decode((string)file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
            if(!is_array($data)||array_is_list($data)||($data['name']??null)!==$name)throw new RuntimeException('Invalid module name: '.$name);
            if(!is_string($data['version']??null)||!preg_match('/^\d+\.\d+\.\d+(?:-[a-z0-9.-]+)?$/Di',$data['version']))throw new RuntimeException('Invalid version: '.$name);
            if(isset($data['required'])&&!is_bool($data['required']))throw new RuntimeException('Invalid required flag: '.$name);
            foreach(['dependencies','providers'] as $field){if(isset($data[$field])&&!is_array($data[$field]))throw new RuntimeException('Invalid '.$field.' for '.$name);}
            if(isset($data['providers'])&&!array_is_list($data['providers']))throw new RuntimeException('Providers must be a list: '.$name);
            foreach($data['providers']??[] as $provider){if(!is_string($provider)||!str_starts_with($provider,'Modules\\'.$name.'\\')||!preg_match('/^[A-Za-z0-9_\\\\]+$/D',$provider))throw new RuntimeException('Unsafe provider name: '.$name);}
            $deps=$data['dependencies']??[];
            if(array_is_list($deps)&&$deps!==[])throw new RuntimeException('Dependencies must be an object: '.$name);
            foreach($deps as $dependency=>$constraint){if(!preg_match('/^[A-Z][A-Za-z0-9]*$/D',(string)$dependency)||!is_string($constraint))throw new RuntimeException('Invalid dependency: '.$name);}
            if(isset($data['framework'])&&!$this->satisfies(self::API_VERSION,(string)$data['framework']))throw new RuntimeException('Framework API incompatible with '.$name);
            if(isset($data['min_release'])&&!$this->satisfies(\Brightery\Core\Kernel::VERSION,(string)$data['min_release']))throw new RuntimeException('Framework release incompatible with '.$name);
            $items[$name]=$data;
        }
        ksort($items);return $this->catalogue=$items;
    }
    /** Minimal explicit semver constraints: *, exact, ^x.y.z, >=x.y.z. */
    private function satisfies(string $version,string $constraint):bool {
        if($constraint==='*')return true;
        if(preg_match('/^>=([0-9]+\.[0-9]+\.[0-9]+)$/D',$constraint,$m))return version_compare($version,$m[1],'>=');
        if(preg_match('/^\^([0-9]+)\.([0-9]+)\.([0-9]+)$/D',$constraint,$m)){
            $floor=implode('.',array_slice($m,1));
            $upper=(int)$m[1]>0?((int)$m[1]+1).'.0.0':'0.'.((int)$m[2]+1).'.0';
            return version_compare($version,$floor,'>=')&&version_compare($version,$upper,'<');
        }
        if(preg_match('/^\d+\.\d+\.\d+$/D',$constraint))return version_compare($version,$constraint,'==');
        throw new RuntimeException('Unsupported version constraint: '.$constraint);
    }
    public function states():array {
        if(!is_file($this->stateFile))return [];
        $data=json_decode((string)file_get_contents($this->stateFile),true);
        if(!is_array($data)||array_is_list($data))throw new RuntimeException('Corrupted module states file');
        return $data;
    }
    public function enabled(string $name):bool {
        $catalogue=$this->available();
        if(!isset($catalogue[$name]))return false;
        return ($catalogue[$name]['required']??false)===true||($this->states()[$name]??false)===true;
    }
    public function ordered(bool $onlyEnabled=true,?array $states=null):array {
        $catalogue=$this->available();$states??=$this->states();
        $order=[];$visiting=[];$visited=[];
        $walk=function(string $name)use(&$walk,&$order,&$visiting,&$visited,$catalogue,$states,$onlyEnabled):void {
            if(isset($visited[$name]))return;
            if(isset($visiting[$name]))throw new RuntimeException('Module dependency cycle involving '.$name);
            if(!isset($catalogue[$name]))throw new RuntimeException('Unknown module dependency: '.$name);
            $active=($catalogue[$name]['required']??false)===true||($states[$name]??false)===true;
            if($onlyEnabled&&!$active)return;
            $visiting[$name]=true;
            foreach($catalogue[$name]['dependencies']??[] as $dependency=>$constraint){
                if(!isset($catalogue[$dependency]))throw new RuntimeException($name.' requires missing '.$dependency);
                if(!$this->satisfies($catalogue[$dependency]['version'],$constraint))throw new RuntimeException($name.' requires '.$dependency.' '.$constraint);
                $depActive=($catalogue[$dependency]['required']??false)===true||($states[$dependency]??false)===true;
                if($onlyEnabled&&!$depActive)throw new RuntimeException($name.' requires enabled '.$dependency);
                $walk($dependency);
            }
            unset($visiting[$name]);$visited[$name]=true;$order[$name]=$catalogue[$name];
        };
        foreach(array_keys($catalogue) as $name)$walk($name);
        return $order;
    }
    public function validate():array {return array_keys($this->ordered(false));}
    /** Pure preview of a proposed activation, suitable for admin review and CLI dry runs. */
    public function planChange(string $name,bool $enabled,?array $states=null):array {
        $catalogue=$this->available();
        if(!isset($catalogue[$name]))throw new RuntimeException('Unknown module: '.$name);
        if(($catalogue[$name]['required']??false)===true && !$enabled)throw new RuntimeException('Required modules cannot be disabled');
        $states??=$this->states();
        $current=($catalogue[$name]['required']??false)===true||($states[$name]??false)===true;
        $proposed=$states;$proposed[$name]=$enabled;
        $this->ordered(true,$proposed); // Validate all dependants, versions, and cycles before approval.
        $dependants=[];
        foreach($catalogue as $other=>$manifest)if(isset($manifest['dependencies'][$name]))$dependants[]=$other;
        $digest=$this->changeDigest($catalogue,$states,$name,$enabled);
        return ['name'=>$name,'current'=>$current,'target'=>$enabled,'changed'=>$current!==$enabled,
            'version'=>$catalogue[$name]['version'],'dependencies'=>$catalogue[$name]['dependencies']??[],
            'dependants'=>$dependants,'token'=>$digest,
            'warnings'=>['Run new migrations separately after activation, if the module supplies them.',
                'Reload PHP-FPM and restart long-running workers after the change; this request uses the current module graph.']];
    }
    private function changeDigest(array $catalogue,array $states,string $name,bool $enabled):string {
        ksort($states);
        $metadata=[];
        foreach($catalogue as $key=>$value)$metadata[$key]=[$value['version'],$value['dependencies']??[],$value['required']??false];
        return hash('sha256',json_encode([$metadata,$states,$name,$enabled],JSON_THROW_ON_ERROR));
    }
    /** Optional expected token makes admin confirmation atomic with state change under the lock. */
    public function setEnabled(string $name,bool $enabled,?string $expectedToken=null):void {
        $catalogue=$this->available();
        if(!isset($catalogue[$name]))throw new RuntimeException('Unknown module: '.$name);
        if(($catalogue[$name]['required']??false)===true)throw new RuntimeException('Required modules cannot be disabled');
        $directory=dirname($this->stateFile);
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Cannot create state directory');
        $lock=fopen($this->stateFile.'.lock','c');
        if($lock===false)throw new RuntimeException('Cannot open module state lock');
        try {
            if(!flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock module state');
            $states=$this->states();
            if($expectedToken!==null){
                if(!preg_match('/^[a-f0-9]{64}$/D',$expectedToken))throw new RuntimeException('Invalid module change confirmation');
                $fresh=$this->planChange($name,$enabled,$states);
                if(!hash_equals($fresh['token'],$expectedToken))throw new RuntimeException('Module state or versions changed; review the plan again');
            }
            $states[$name]=$enabled;
            // Validate proposed dependency state before changing on-disk activation.
            $this->ordered(true,$states);
            $json=json_encode($states,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
            $tmp=tempnam($directory,'module-');
            if($tmp===false)throw new RuntimeException('Cannot create temporary module state');
            try {
                if(file_put_contents($tmp,$json,LOCK_EX)===false)throw new RuntimeException('Cannot write module state');
                chmod($tmp,0600);
                if(!rename($tmp,$this->stateFile))throw new RuntimeException('Cannot replace module state');
            } finally {if(is_file($tmp))@unlink($tmp);}
        } finally {flock($lock,LOCK_UN);fclose($lock);}

    }
    public function boot(Container $container,Hooks $hooks):void {
        $modules=$this->ordered();$providers=[];
        foreach($modules as $name=>$info){
            foreach($info['providers']??[] as $class){
                $provider=new $class();
                if(!$provider instanceof ServiceProviderInterface)throw new RuntimeException($class.' must implement ServiceProviderInterface');
                $provider->register($container);$providers[]=$provider;
            }
        }
        foreach($providers as $provider)$provider->boot($container,$hooks);
        foreach($modules as $name=>$info){
            $file=$this->modulesDirectory.'/'.$name.'/boot.php';
            if(!is_file($file))continue;
            $bootstrap=require $file;
            if(!is_callable($bootstrap))throw new RuntimeException('Invalid module bootstrap: '.$name);
            $bootstrap($container,$hooks);
        }
    }
    public function register(Router $router):void {
        foreach($this->ordered() as $name=>$info){
            $file=$this->modulesDirectory.'/'.$name.'/routes.php';
            if(!is_file($file))continue;
            $registrar=require $file;
            if(!is_callable($registrar))throw new RuntimeException('Invalid module routes: '.$name);
            $registrar($router);
        }
    }
    /** Namespaced migration paths; safe to inspect a disabled module before it is activated. */
    public function migrationPathsFor(string $name):array {
        if(!isset($this->available()[$name]))throw new RuntimeException('Unknown module: '.$name);
        $directory=$this->modulesDirectory.'/'.$name.'/database/migrations';
        if(is_link($directory)||is_link(dirname($directory)))throw new RuntimeException('Unsafe module migration directory');
        $paths=[];
        foreach(glob($directory.'/*.sql')?:[] as $file){
            if(is_link($file)||!is_file($file))throw new RuntimeException('Unsafe module migration file');
            $paths['module_'.$name.'__'.basename($file)]=$file;
        }
        ksort($paths);
        return $paths;
    }
    public function migrationPaths():array {
        $paths=[];
        foreach($this->ordered() as $name=>$manifest)$paths += $this->migrationPathsFor($name);
        return $paths;
    }
}
