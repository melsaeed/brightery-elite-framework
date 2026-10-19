<?php
declare(strict_types=1);
namespace Brightery\Core\Operations;
use Brightery\Core\Database\Migrator;
use Brightery\Core\Cache\FileCache;
use Brightery\Core\Kernel;
use RuntimeException;

/** Finalize pre-deployed framework code under a local process lock. Never swaps code or rolls back DDL. */
final class ReleaseCoordinator {
    public function __construct(private readonly ReleasePreflight $preflight,private readonly Migrator $migrator,private readonly string $privateDir){}
    public function current():?array {
        $path=$this->privateDir.'/release-state.json';
        if(!is_file($path)||is_link($path))return null;
        $data=json_decode((string)file_get_contents($path),true);
        return is_array($data)&&isset($data['version'])?$data:null;
    }
    public function plan():array {return ['release'=>Kernel::VERSION,'current'=>$this->current(),'preflight'=>$this->preflight->inspect()];}
    public function apply():array {
        if(PHP_SAPI!=='cli')throw new RuntimeException('Deployment finalization requires CLI');
        if((string)env('APP_ENV','local')==='production' && (string)env('DEPLOY_MAINTENANCE','false')!=='true')throw new RuntimeException('Production requires DEPLOY_MAINTENANCE=true and external traffic/worker drain');
        if(!is_dir($this->privateDir)||is_link($this->privateDir))throw new RuntimeException('Private state directory is missing/unsafe');
        $path=$this->privateDir.'/release.lock';
        if(is_link($path))throw new RuntimeException('Unsafe release lock path');
        $lock=fopen($path,'c');if($lock===false)throw new RuntimeException('Cannot open release lock');
        try {
            if(!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Another deployment is running');
            $preflight=$this->preflight->inspect();
            if(!$preflight['ready'])throw new RuntimeException('Deployment preflight failed');
            $migrations=$this->migrator->run();
            $after=$this->preflight->inspect();
            if(!$after['ready']||array_filter($after['checks'],static fn($c)=>$c['name']==='migrations'&&$c['status']!=='pass'))throw new RuntimeException('Post-migration preflight failed');
            (new FileCache(base_path('storage/cache')))->flush();
            $previous=$this->current();
            $state=['version'=>Kernel::VERSION,'finalized_at'=>gmdate('c'),'previous_version'=>$previous['version']??null,'migrations_applied'=>$migrations];
            $temp=tempnam($this->privateDir,'release-');if($temp===false)throw new RuntimeException('Cannot create release state');
            try {
                if(file_put_contents($temp,json_encode($state,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),LOCK_EX)===false)throw new RuntimeException('Cannot write release state');
                chmod($temp,0600);
                if(!rename($temp,$this->privateDir.'/release-state.json'))throw new RuntimeException('Cannot publish release state');
            }finally{if(is_file($temp))unlink($temp);}
            return $state;
        } finally {flock($lock,LOCK_UN);fclose($lock);}
    }
}
