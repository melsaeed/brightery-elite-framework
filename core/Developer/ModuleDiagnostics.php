<?php
declare(strict_types=1);
namespace Brightery\Core\Developer;
use InvalidArgumentException;
/** Read-only diagnostics: validates metadata/files, never evaluates or runs generated code. */
final class ModuleDiagnostics {
    public function __construct(private readonly string $moduleRoot){}
    public function inspect(string $name):array {
        if(!preg_match('/^[A-Z][A-Za-z0-9]{1,35}$/D',$name))throw new InvalidArgumentException('Invalid module name');
        $root=rtrim($this->moduleRoot,'/');$dir=$root.'/'.$name;
        if(is_link($root)||is_link($dir)||!is_dir($dir))throw new InvalidArgumentException('Unsafe or missing module');
        $checks=[];$mark=static function(string $label,bool $okay,string $detail='')use(&$checks):void{$checks[]=['name'=>$label,'ok'=>$okay,'detail'=>$detail];};
        $manifest=$dir.'/module.json';
        $mark('Module manifest',!is_link($manifest)&&is_file($manifest));
        $data=null;
        if(is_file($manifest)&&!is_link($manifest)&&filesize($manifest)<=16384){
            try{$data=json_decode((string)file_get_contents($manifest),true,16,JSON_THROW_ON_ERROR);}catch(\Throwable){}
        }
        $mark('Manifest matches directory',is_array($data)&&($data['name']??null)===$name);
        $routes=$dir.'/routes.php';$mark('Routes file',!is_link($routes)&&is_file($routes));
        $blueprint=$dir.'/blueprint.json';
        if(is_file($blueprint)||is_link($blueprint)){
            try{
                $s=ModuleBlueprint::fromFile($blueprint);
                $mark('Canonical blueprint',$s['name']===$name);
                foreach(['Controllers/CrudController.php','Models/RecordRepository.php','Policies/RecordPolicy.php','database/migrations/001_create.sql'] as $relative){
                    $path=$dir.'/'.$relative;
                    $mark($relative,!is_link($path)&&is_file($path));
                }
                $migrationFiles=glob($dir.'/database/migrations/*.sql')?:[];
                $mark('SQL migration paths safe',count($migrationFiles)>0&&count(array_filter($migrationFiles,static fn($path)=>is_link($path)))===0);
            }catch(\Throwable $e){$mark('Canonical blueprint',false,'The schema is invalid or incompatible');}
        }else{$mark('Application blueprint',true,'Not a generated CRUD module; not required');}
        $failed=count(array_filter($checks,static fn($entry)=>!$entry['ok']));
        return ['name'=>$name,'ok'=>$failed===0,'failed'=>$failed,'checks'=>$checks];
    }
}
