<?php
declare(strict_types=1);
namespace Brightery\Core\Modules;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Maintenance-window, CLI-only signed module installer with preflight and local file rollback. */
final class ModuleInstaller {
    public function __construct(
        private readonly string $modulesRoot,
        private readonly string $stateFile,
        private readonly string $privateRoot,
        private readonly TrustedKeys $trust
    ){}
    public function inspect(string $file):array {
        $payload=$this->trustedPayload($file);
        return $this->plan($payload);
    }
    public function install(string $file,bool $apply=false):array {
        $payload=$this->trustedPayload($file);
        if(!$apply)return $this->plan($payload);
        return $this->withLock(function()use($payload):array {
            $plan=$this->plan($payload);$name=$plan['name'];$target=$this->modulesRoot.'/'.$name;
            $stage=$this->modulesRoot.'/.stage-'.bin2hex(random_bytes(12));
            $replaced=$this->modulesRoot.'/.replaced-'.bin2hex(random_bytes(12));
            $backupId=null;$oldMoved=false;$newMoved=false;
            try{
                mkdir($stage,0700,true);
                foreach($payload['files'] as $path=>$encoded){
                    ModulePackage::filePath($path);
                    $full=$stage.'/'.$path;$directory=dirname($full);
                    if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Failed to create staging directory');
                    $bytes=base64_decode($encoded,true);
                    if($bytes===false||file_put_contents($full,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('Failed to write staged module');
                    chmod($full,0644);
                }
                if(is_dir($target)){
                    $backupId=gmdate('YmdHis').'-'.bin2hex(random_bytes(6));
                    $backup=$this->backupPath($name,$backupId);
                    mkdir($backup,0700,true);
                    self::copyTree($target,$backup.'/module');
                    file_put_contents($backup.'/backup.json',json_encode([
                        'name'=>$name,'backed_up_version'=>$plan['previous_version'],
                        'replaced_by_version'=>$plan['version'],'created_utc'=>gmdate('c')
                    ],JSON_THROW_ON_ERROR));
                    if(!rename($target,$replaced))throw new RuntimeException('Cannot move installed module to recovery slot');
                    $oldMoved=true;
                }
                if(!rename($stage,$target))throw new RuntimeException('Cannot publish staged module');
                $newMoved=true;
                $plan['applied']=true;$plan['backup_id']=$backupId;
                return $plan;
            }catch(\Throwable $error){
                if($newMoved&&is_dir($target))self::removeTree($target);
                if($oldMoved&&is_dir($replaced)){if(!rename($replaced,$target))throw new RuntimeException('INSTALL RECOVERY REQUIRED: '.$replaced,0,$error);}
                throw $error;
            }finally{
                if(is_dir($stage))self::removeTree($stage);
                if($newMoved&&is_dir($replaced))self::removeTree($replaced);
            }
        });
    }
    public function rollbacks(string $name):array {
        ModulePackage::name($name);
        $root=$this->privateRoot.'/module-backups/'.$name;
        $ids=[];
        foreach(glob($root.'/*/backup.json')?:[] as $file){
            $id=basename(dirname($file));
            if(preg_match('/^\d{14}-[a-f0-9]{12}$/D',$id))$ids[]=$id;
        }
        rsort($ids);return $ids;
    }
    public function rollback(string $name,string $backupId,bool $apply=false):array {
        ModulePackage::name($name);
        $backup=$this->backupPath($name,$backupId);
        $metaFile=$backup.'/backup.json';$old=$backup.'/module';
        if(!is_file($metaFile)||!is_dir($old)||is_link($old))throw new RuntimeException('Unknown module backup');
        $meta=json_decode((string)file_get_contents($metaFile),true,16,JSON_THROW_ON_ERROR);
        if(($meta['name']??null)!==$name)throw new RuntimeException('Backup metadata mismatch');
        $target=$this->modulesRoot.'/'.$name;
        if(!is_dir($target)||is_link($target))throw new RuntimeException('Current module is unavailable');
        $current=json_decode((string)file_get_contents($target.'/module.json'),true,16,JSON_THROW_ON_ERROR);
        if(($current['version']??null)!==($meta['replaced_by_version']??null))throw new RuntimeException('Current module changed since backup; manual review required');
        $new=json_decode((string)file_get_contents($old.'/module.json'),true,16,JSON_THROW_ON_ERROR);
        if(($new['name']??null)!==$name||($new['version']??null)!==($meta['backed_up_version']??null))throw new RuntimeException('Backup module version mismatch');
        $plan=$this->plan(['name'=>$name,'version'=>$new['version'],'files'=>['module.json'=>base64_encode((string)file_get_contents($old.'/module.json'))]],true);
        $plan['rollback_id']=$backupId;
        if(!$apply)return $plan;
        return $this->withLock(function()use($name,$backupId,$old,$target,$plan):array {
            $this->rollback($name,$backupId,false); // Recheck while holding lock.
            $stage=$this->modulesRoot.'/.stage-'.bin2hex(random_bytes(12));
            $replaced=$this->modulesRoot.'/.replaced-'.bin2hex(random_bytes(12));
            $oldMoved=false;$newMoved=false;
            try{
                self::copyTree($old,$stage);
                if(!rename($target,$replaced))throw new RuntimeException('Cannot stage current module for rollback');
                $oldMoved=true;
                if(!rename($stage,$target))throw new RuntimeException('Cannot restore module backup');
                $newMoved=true;
                return [...$plan,'applied'=>true];
            }catch(\Throwable $error){
                if($newMoved&&is_dir($target))self::removeTree($target);
                if($oldMoved&&is_dir($replaced)&&!rename($replaced,$target))throw new RuntimeException('ROLLBACK RECOVERY REQUIRED: '.$replaced,0,$error);
                throw $error;
            }finally{
                if(is_dir($stage))self::removeTree($stage);
                if($newMoved&&is_dir($replaced))self::removeTree($replaced);
            }
        });
    }
    private function plan(array $payload,bool $isRollback=false):array {
        $name=$payload['name'];ModulePackage::name($name);
        if(in_array($name,['System','Api'],true))throw new RuntimeException('Framework-managed modules must be updated with framework releases');
        $target=$this->modulesRoot.'/'.$name;
        if(is_link($target))throw new RuntimeException('Installed module cannot be symlinked');
        $current=null;
        if(is_dir($target)){
            if(!is_file($target.'/module.json')||is_link($target.'/module.json'))throw new RuntimeException('Installed module manifest is unsafe');
            $current=json_decode((string)file_get_contents($target.'/module.json'),true,16,JSON_THROW_ON_ERROR);
            if(($current['name']??null)!==$name)throw new RuntimeException('Existing module name mismatch');
            if(($current['required']??false)===true)throw new RuntimeException('Cannot replace a required module');
            if(!$isRollback&&version_compare($payload['version'],(string)$current['version'],'<='))throw new RuntimeException('Package version must be greater than the installed version');
        }
        $this->validateGraph($name,base64_decode($payload['files']['module.json'],true));
        return ['name'=>$name,'version'=>$payload['version'],'previous_version'=>$current['version']??null,
            'action'=>$isRollback?'rollback':($current?'update':'install'),
            'applied'=>false,'file_count'=>count($payload['files']),
            'database_migrations'=>'NOT APPLIED; schema rollback is unsupported'];
    }
    private function validateGraph(string $candidate,string $json):void {
        $tmp=$this->privateRoot.'/module-check-'.bin2hex(random_bytes(10));
        mkdir($tmp,0700,true);
        try{
            foreach(glob($this->modulesRoot.'/*/module.json')?:[] as $path){
                $name=basename(dirname($path));
                if(is_link($path)||is_link(dirname($path)))throw new RuntimeException('Unsafe installed module during preflight');
                $dest=$tmp.'/'.$name;
                mkdir($dest,0700,true);
                if(!copy($path,$dest.'/module.json'))throw new RuntimeException('Cannot create dependency graph snapshot');
            }
            if(!is_dir($tmp.'/'.$candidate))mkdir($tmp.'/'.$candidate,0700,true);
            file_put_contents($tmp.'/'.$candidate.'/module.json',$json);
            $manager=new ModuleManager($this->stateFile,$tmp);
            $manager->validate();$manager->ordered(true);
        }finally{self::removeTree($tmp);}
    }
    private function trustedPayload(string $file):array {
        if(!is_file($file)||is_link($file)||filesize($file)>ModulePackage::MAX_PACKAGE_BYTES)throw new RuntimeException('Module package unavailable or too large');
        $content=file_get_contents($file);
        if($content===false)throw new RuntimeException('Cannot read module package');
        return ModulePackage::verify(ModulePackage::decode($content),$this->trust->all());
    }
    private function backupPath(string $name,string $id):string {
        if(!preg_match('/^\d{14}-[a-f0-9]{12}$/D',$id))throw new RuntimeException('Invalid backup identifier');
        return $this->privateRoot.'/module-backups/'.$name.'/'.$id;
    }
    private function withLock(callable $operation):array {
        if(!is_dir($this->modulesRoot)||!is_dir($this->privateRoot))throw new RuntimeException('Installer directories must exist');
        $lock=fopen($this->privateRoot.'/module-install.lock','c');if($lock===false)throw new RuntimeException('Installer lock unavailable');
        try{
            if(!flock($lock,LOCK_EX))throw new RuntimeException('Installer lock unavailable');
            return $operation();
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    private static function copyTree(string $source,string $destination):void {
        if(!is_dir($source)||is_link($source))throw new RuntimeException('Unsafe module tree');
        if(!mkdir($destination,0700,true))throw new RuntimeException('Cannot create module copy');
        $count=0;$total=0;
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $node){
            if($node->isLink())throw new RuntimeException('Symlink detected in module backup');
            $relative=substr($node->getPathname(),strlen(rtrim($source,'/'))+1);
            ModulePackage::filePath($relative);
            $dest=$destination.'/'.$relative;
            if($node->isDir()){if(!mkdir($dest,0700,true)&&!is_dir($dest))throw new RuntimeException('Cannot create backup folder');}
            elseif($node->isFile()){
                if(++$count>2500||($total+=$node->getSize())>104857600)throw new RuntimeException('Installed module backup limit exceeded');
                if(!copy($node->getPathname(),$dest))throw new RuntimeException('Cannot copy module file');
            }else throw new RuntimeException('Unsupported module filesystem entry');
        }
    }
    private static function removeTree(string $directory):void {
        if(!is_dir($directory)||is_link($directory))return;
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $node){
            if($node->isLink()||$node->isFile())unlink($node->getPathname());
            elseif($node->isDir())rmdir($node->getPathname());
        }
        rmdir($directory);
    }
}
