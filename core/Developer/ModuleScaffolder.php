<?php
declare(strict_types=1);
namespace Brightery\Core\Developer;
use Brightery\Core\Modules\ModuleManager;
use RuntimeException;

/** Deterministic templates, no eval or arbitrary PHP from user-controlled input. */
final class ModuleScaffolder {
    private readonly string $directory;
    public function __construct(?string $directory=null){$this->directory=rtrim($directory??base_path('modules'),'/');}
    private static function quote(string $s):string{return var_export($s,true);}
    public function files(array $raw):array {
        $s=ModuleBlueprint::normalize($raw);
        $name=$s['name'];$slug=$s['slug'];$table=$s['table'];$scope=$s['scope'];
        $manifest=['name'=>$name,'version'=>'0.1.0','description'=>$s['title'].' — generated '.$scope.' CRUD module','required'=>false,'framework'=>'^0.2.0','min_release'=>'>=1.6.0','dependencies'=>['System'=>'>=0.3.0']];
        foreach($s['fields'] as $field)if(in_array($field['type'],['relation','many_to_many'],true))$manifest['dependencies'][$field['references']]='>=0.1.0';
        $sqlColumns=["`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY"];
        if($scope==='tenant')$sqlColumns[]='`tenant_id` BIGINT UNSIGNED NOT NULL';
        foreach($s['fields'] as $f){
            if($f['type']==='many_to_many')continue;
            $type=match($f['type']){'text'=>'VARCHAR(255)','textarea'=>'TEXT','email'=>'VARCHAR(190)','integer'=>'BIGINT','decimal'=>'DECIMAL(10,2)','date'=>'DATE','boolean'=>'TINYINT(1)','relation'=>'BIGINT UNSIGNED'};
            $sqlColumns[]='`'.$f['name'].'` '.$type.($f['required']?' NOT NULL':' NULL');
        }
        $this->validateReferences($s);
        $sqlColumns[]='`created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP';
        $sqlColumns[]='`updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP';
        if($s['soft_delete']??false)$sqlColumns[]='`deleted_at` TIMESTAMP NULL DEFAULT NULL';
        if($scope==='tenant')$sqlColumns[]='KEY `idx_tenant_id` (`tenant_id`,`id`)';
        if($s['soft_delete']??false)$sqlColumns[]=$scope==='tenant'?'KEY `idx_tenant_active` (`tenant_id`,`deleted_at`,`id`)':'KEY `idx_active` (`deleted_at`,`id`)';
        $sql="CREATE TABLE IF NOT EXISTS `{$table}` (\n  ".implode(",\n  ",$sqlColumns)."\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";
        foreach($s['fields'] as $field){
            if($field['type']!=='many_to_many')continue;
            $pivot=ModuleBlueprint::pivotFor($name,$field['name']);
            $target=ModuleBlueprint::tableFor($field['references']);
            $pcols=['`source_id` BIGINT UNSIGNED NOT NULL','`target_id` BIGINT UNSIGNED NOT NULL'];
            if($scope==='tenant')$pcols[]='`tenant_id` BIGINT UNSIGNED NOT NULL';
            $pcols[]='PRIMARY KEY (`source_id`,`target_id`)';
            $pcols[]='KEY `idx_target` (`target_id`)';
            if($scope==='tenant')$pcols[]='KEY `idx_tenant_source` (`tenant_id`,`source_id`)';
            $pcols[]='CONSTRAINT `fk_'.substr(hash('sha256',$pivot.'src'),0,24).'` FOREIGN KEY (`source_id`) REFERENCES `'.$table.'` (`id`) ON DELETE CASCADE';
            $pcols[]='CONSTRAINT `fk_'.substr(hash('sha256',$pivot.'dst'),0,24).'` FOREIGN KEY (`target_id`) REFERENCES `'.$target.'` (`id`) ON DELETE CASCADE';
            $sql.='CREATE TABLE IF NOT EXISTS `'.$pivot.'` ('."\n  ".implode(",\n  ",$pcols)."\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";
        }
        if($scope==='central')$sql.="INSERT IGNORE INTO permissions(code,label) VALUES ('module.{$slug}.read','View {$name} records'),('module.{$slug}.write','Edit {$name} records');\n";
        $prefix=($scope==='tenant'?'/workspace/':'/admin/').$slug;
        $guard=$scope==='tenant'?"\\Brightery\\Core\\App::get()->service(\\Brightery\\Core\\Tenancy\\TenantAccess::class)->guard": "\\Brightery\\Core\\App::get()->service(\\Brightery\\Core\\Auth::class)->guard";
        $read=$scope==='tenant'?'content.read':'module.'.$slug.'.read';$write=$scope==='tenant'?'content.write':'module.'.$slug.'.write';
        $routes="<?php\ndeclare(strict_types=1);\nuse Brightery\\Core\\Http\\Router;\nuse Modules\\{$name}\\Controllers\\CrudController as C;\nreturn static function(Router \$r):void {\n";
        $routes.='    $read='.$guard.'('.self::quote($read).');' . "\n";
        $routes.='    $write='.$guard.'('.self::quote($write).');' . "\n";
        $routes.="    \$r->get('{$prefix}',[C::class,'index'],[\$read],".self::quote('generated.'.$slug.'.index').");\n";
        $routes.="    \$r->post('{$prefix}/save',[C::class,'save'],[\$write]);\n";
        $routes.="    \$r->post('{$prefix}/delete',[C::class,'remove'],[\$write]);\n";
        $routes.="    \$r->get('{$prefix}/relations',[C::class,'relationLookup'],[\$read]);\n";
        $routes.="    \$r->get('{$prefix}/export',[C::class,'exportCsv'],[\$read]);\n";
        $routes.="    \$r->post('{$prefix}/bulk-delete',[C::class,'bulkRemove'],[\$write]);\n";
        if($s['soft_delete']??false){$routes.="    \$r->post('{$prefix}/restore',[C::class,'restore'],[\$write]);\n";
            $routes.="    \$r->post('{$prefix}/bulk-restore',[C::class,'bulkRestore'],[\$write]);\n";}
        $routes.="    \$r->get('/api/v1/modules/{$slug}',[C::class,'apiList']);\n";
        $routes.="    \$r->post('/api/v1/modules/{$slug}',[C::class,'apiCreate']);\n";
        $routes.="    \$r->add('PATCH','/api/v1/modules/{$slug}/{id}',[C::class,'apiUpdate']);\n";
        $routes.="    \$r->add('DELETE','/api/v1/modules/{$slug}/{id}',[C::class,'apiDelete']);\n};\n";
        $repository="<?php\ndeclare(strict_types=1);\nnamespace Modules\\{$name}\\Models;\nuse Brightery\\Core\\{App,Database};\nuse Brightery\\Core\\Tenancy\\TenantRecords;\nuse Brightery\\Core\\Developer\\{CrudRepository,ModuleBlueprint};\n/** Application-owned model: customize without changing core. */\nfinal class RecordRepository extends CrudRepository {\n    public function __construct(){\n        parent::__construct(App::get()->service(Database::class),App::get()->service(TenantRecords::class),ModuleBlueprint::fromFile(dirname(__DIR__).'/blueprint.json'),new \\Modules\\{$name}\\Policies\\RecordPolicy());\n    }\n}\n";
        $controller="<?php\ndeclare(strict_types=1);\nnamespace Modules\\{$name}\\Controllers;\nuse Brightery\\Core\\Developer\\CrudEndpoints;\nuse Brightery\\Core\\Http\\{Request,Response};\nuse Modules\\{$name}\\Models\\RecordRepository;\n/** Application-owned MVC controller. Extend here; never patch framework core. */\nfinal class CrudController {\n    private static function action():CrudEndpoints{return new CrudEndpoints(new RecordRepository());}\n    public static function index(Request \$r):Response{return self::action()->index(\$r);}\n    public static function save(Request \$r):Response{return self::action()->save(\$r);}\n    public static function remove(Request \$r):Response{return self::action()->remove(\$r);}\n    public static function relationLookup(Request \$r):Response{return self::action()->relationLookup(\$r);}
    public static function bulkRemove(Request \$r):Response{return self::action()->bulkRemove(\$r);}
    public static function bulkRestore(Request \$r):Response{return self::action()->bulkRestore(\$r);}
    public static function exportCsv(Request \$r):Response{return self::action()->exportCsv(\$r);}
    public static function restore(Request \$r):Response{return self::action()->restore(\$r);}
    public static function apiList(Request \$r):Response{return self::action()->apiList(\$r);}\n    public static function apiCreate(Request \$r):Response{return self::action()->apiCreate(\$r);}\n    public static function apiUpdate(Request \$r,array \$params):Response{return self::action()->apiUpdate(\$r,\$params);}\n    public static function apiDelete(Request \$r,array \$params):Response{return self::action()->apiDelete(\$r,\$params);}\n}\n";
        $policy="<?php\ndeclare(strict_types=1);\nnamespace Modules\\{$name}\\Policies;\nuse Brightery\\Core\\Developer\\CrudValidationPolicy;\nuse InvalidArgumentException;\n/** Optional business-specific validation. Validated input has already passed core field/type checks. */\nfinal class RecordPolicy implements CrudValidationPolicy {\n    public function validate(array \$schema,array \$values,bool \$partial):void {\n        // Add domain restrictions here; throw InvalidArgumentException on invalid input.\n    }\n}\n";
        $files=[
            'module.json'=>json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",
            'blueprint.json'=>json_encode($s,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n",
            'database/migrations/001_create.sql'=>$sql,
            'routes.php'=>$routes,
            'Models/RecordRepository.php'=>$repository,
            'Policies/RecordPolicy.php'=>$policy,
            'Controllers/CrudController.php'=>$controller,
            'README.md'=>"# {$name}\n\nApp-owned {$scope} CRUD module. Enable with `php bin/console module:enable {$name}` and run `php bin/console migrate`.\n\nUI: `{$prefix}`. API: `/api/v1/modules/{$slug}` (bearer scopes `modules:read` and `modules:write`).\n\nEdits in this directory survive framework upgrades when upgrades do not overwrite app-owned modules. Back up before all upgrades.\n"
        ];
        return $files;
    }
    /** References must point to installed, generated modules with the same data boundary. */
    public function validateReferences(array $schema):void {
        foreach($schema['fields'] as $field){
            if(!in_array($field['type'],['relation','many_to_many'],true))continue;
            $target=$field['references'];$dir=$this->directory.'/'.$target;
            if(is_link($dir)||!is_dir($dir))throw new RuntimeException('Reference module does not exist: '.$target);
            $candidate=ModuleBlueprint::fromFile($dir.'/blueprint.json');
            if($candidate['name']!==$target||$candidate['scope']!==$schema['scope'])throw new RuntimeException('Relation must reference a generated module in the same scope');
        }
    }
    public function directory():string{return $this->directory;}
    public function plan(array $raw):array{
        $s=ModuleBlueprint::normalize($raw);$destination=$this->directory.'/'.$s['name'];
        $files=$this->files($raw);
        if(file_exists($destination)||is_link($destination))throw new RuntimeException('Module already exists; refusing to overwrite');
        if(!is_dir($this->directory)||is_link($this->directory))throw new RuntimeException('Modules directory is unavailable or unsafe');
        $paths=array_keys($files);
        return ['schema'=>$s,'files'=>$paths,'sql'=>$files['database/migrations/001_create.sql'],'digest'=>hash('sha256',json_encode($s,JSON_THROW_ON_ERROR))];
    }
    public function apply(array $raw,string $digest):array{
        $s=ModuleBlueprint::normalize($raw);
        $current=hash('sha256',json_encode($s,JSON_THROW_ON_ERROR));
        if(!preg_match('/^[a-f0-9]{64}$/D',$digest)||!hash_equals($current,$digest))throw new RuntimeException('Blueprint changed; review again');
        if(!is_dir($this->directory)||is_link($this->directory))throw new RuntimeException('Unsafe module directory');
        $lock=fopen($this->directory.'/.builder.lock','c');if(!$lock)throw new RuntimeException('Cannot open builder lock');
        try{
            if(!flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock module builder');
            $plan=$this->plan($s);$files=$this->files($s);
            $stage=$this->directory.'/.building-'.bin2hex(random_bytes(10));
            if(!mkdir($stage,0700))throw new RuntimeException('Cannot create staging directory');
            try{
                foreach($files as $relative=>$body){
                    $dest=$stage.'/'.$relative;$dir=dirname($dest);
                    if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Cannot create staging subdirectory');
                    if(file_put_contents($dest,$body,LOCK_EX)!==strlen($body))throw new RuntimeException('Incomplete module file write');
                    chmod($dest,0644);
                }
                // Filesystem publication is atomic when rename stays within the same directory.
                if(file_exists($this->directory.'/'.$s['name'])||is_link($this->directory.'/'.$s['name']))throw new RuntimeException('Module already exists');
                if(!rename($stage,$this->directory.'/'.$s['name']))throw new RuntimeException('Could not publish generated module');
            }finally{
                if(is_dir($stage)){
                    $walk=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
                    foreach($walk as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}
                    rmdir($stage);
                }
            }
            return $plan;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
