<?php
declare(strict_types=1);
namespace Brightery\Core\Developer;
use RuntimeException;

/** Append-only schema changes, reviewed before writing; never touches generated PHP or existing migrations. */
final class ModuleEvolver {
    public function __construct(private readonly string $moduleRoot){}
    private function directory(string $name):string {
        if(!preg_match('/^[A-Z][A-Za-z0-9]{1,35}$/D',$name))throw new RuntimeException('Invalid module name');
        if(is_link($this->moduleRoot))throw new RuntimeException('Module root cannot be a symlink');
        $dir=rtrim($this->moduleRoot,'/').'/'.$name;
        if(is_link($dir)||!is_dir($dir))throw new RuntimeException('Module directory missing or unsafe');
        return $dir;
    }
    /** New fields must be optional; existing definition, order, type and identity cannot change. */
    private function validate(string $name,array $next):array {
        $dir=$this->directory($name);$current=ModuleBlueprint::fromFile($dir.'/blueprint.json');
        $updated=ModuleBlueprint::normalize($next);
        if($updated['name']!==$name||$updated['scope']!==$current['scope']||$updated['table']!==$current['table']||$updated['title']!==$current['title'])throw new RuntimeException('Identity, title and scope must remain unchanged');
        $old=$current['fields'];$new=$updated['fields'];
        if(count($new)<=count($old))throw new RuntimeException('Append at least one new field');
        if(array_slice($new,0,count($old))!==$old)throw new RuntimeException('Existing fields cannot be altered, reordered or deleted');
        $extra=array_slice($new,count($old));
        foreach($extra as $field)if($field['required'])throw new RuntimeException('New fields must be optional to preserve existing rows');
        (new ModuleScaffolder($this->moduleRoot))->validateReferences($updated);
        if(is_link($dir.'/database')||is_link($dir.'/database/migrations'))throw new RuntimeException('Unsafe migration directory');
        return [$current,$updated,$extra,$dir];
    }
    private static function column(array $f):string {
        $type=match($f['type']){'text'=>'VARCHAR(255)','textarea'=>'TEXT','email'=>'VARCHAR(190)','integer'=>'BIGINT','decimal'=>'DECIMAL(10,2)','date'=>'DATE','boolean'=>'TINYINT(1)','relation'=>'BIGINT UNSIGNED'};
        return '`'.$f['name'].'` '.$type.' NULL';
    }
    private function build(string $name,array $next):array {
        [$current,$updated,$extra,$dir]=$this->validate($name,$next);
        $before=hash('sha256',json_encode($current,JSON_THROW_ON_ERROR));
        $after=hash('sha256',json_encode($updated,JSON_THROW_ON_ERROR));
        $existing=glob($dir.'/database/migrations/*.sql')?:[];
        if(!$existing)throw new RuntimeException('Module has no base migration');
        $index=count($existing)+1;
        $filename=sprintf('%03d_add_%s.sql',$index,substr($after,0,12));
        $statements=[];
        foreach($extra as $field){
            if($field['type']!=='many_to_many'){$statements[]='ALTER TABLE `'.$updated['table'].'` ADD COLUMN '.self::column($field).';';continue;}
            $pivot=ModuleBlueprint::pivotFor($name,$field['name']);$target=ModuleBlueprint::tableFor($field['references']);
            $columns=['`source_id` BIGINT UNSIGNED NOT NULL','`target_id` BIGINT UNSIGNED NOT NULL'];
            if($updated['scope']==='tenant')$columns[]='`tenant_id` BIGINT UNSIGNED NOT NULL';
            $columns[]='PRIMARY KEY (`source_id`,`target_id`)';$columns[]='KEY `idx_target` (`target_id`)';
            if($updated['scope']==='tenant')$columns[]='KEY `idx_tenant_source` (`tenant_id`,`source_id`)';
            $columns[]='CONSTRAINT `fk_'.substr(hash('sha256',$pivot.'src'),0,24).'` FOREIGN KEY (`source_id`) REFERENCES `'.$updated['table'].'` (`id`) ON DELETE CASCADE';
            $columns[]='CONSTRAINT `fk_'.substr(hash('sha256',$pivot.'dst'),0,24).'` FOREIGN KEY (`target_id`) REFERENCES `'.$target.'` (`id`) ON DELETE CASCADE';
            $statements[]='CREATE TABLE IF NOT EXISTS `'.$pivot.'` ('."\n  ".implode(",\n  ",$columns)."\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        }
        $sql=implode("\n",$statements)."\n";
        return ['module'=>$name,'previous'=>$before,'next'=>$after,'schema'=>$updated,'added'=>$extra,'migration'=>'database/migrations/'.$filename,'sql'=>$sql,'digest'=>hash('sha256',$before.'|'.$after.'|'.$filename.'|'.$sql)];
    }
    public function plan(string $name,array $next):array{return $this->build($name,$next);}
    public function apply(string $name,array $next,string $digest):array {
        if(!preg_match('/^[a-f0-9]{64}$/D',$digest))throw new RuntimeException('Invalid review digest');
        $dir=$this->directory($name);$lock=fopen(rtrim($this->moduleRoot,'/').'/.builder.lock','c');
        if($lock===false)throw new RuntimeException('Cannot lock module root');
        try{
            if(!flock($lock,LOCK_EX))throw new RuntimeException('Cannot acquire module lock');
            $plan=$this->build($name,$next);
            if(!hash_equals($plan['digest'],$digest))throw new RuntimeException('Stale or mismatched schema review');
            $migration=$dir.'/'.$plan['migration'];$blueprint=$dir.'/blueprint.json';
            if(file_exists($migration)||is_link($migration)||is_link($blueprint))throw new RuntimeException('Unsafe or conflicting file');
            $json=json_encode($plan['schema'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
            $temp=$dir.'/.schema-'.bin2hex(random_bytes(10));
            try{
                if(file_put_contents($temp,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('Could not stage schema');
                chmod($temp,0600);
                $sql=$plan['sql'];
                if(file_put_contents($migration,$sql,LOCK_EX)!==strlen($sql))throw new RuntimeException('Could not stage migration');
                if(!rename($temp,$blueprint))throw new RuntimeException('Could not publish schema');
                chmod($blueprint,0644);
            }catch(\Throwable $e){if(is_file($migration))unlink($migration);throw $e;}
            finally{if(is_file($temp))unlink($temp);}
            return $plan;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
