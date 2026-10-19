<?php
declare(strict_types=1);
use Brightery\Core\Developer\{ModuleBlueprint,ModuleScaffolder,CrudRepository,CrudEndpoints};
use Brightery\Core\Database;
use Brightery\Core\Tenancy\{TenantRecords,ContextStore,TenantContext};
use Brightery\Core\Modules\ModuleManager;
use Brightery\Core\Http\Router;
check(version_compare(\Brightery\Core\Kernel::VERSION,'1.3.0','>='),'v1.2 release version');
$root=sys_get_temp_dir().'/bf-v12-'.bin2hex(random_bytes(7));mkdir($root.'/System',0700,true);
file_put_contents($root.'/System/module.json',json_encode(['name'=>'System','required'=>true,'version'=>'0.3.5']));
$schema=['name'=>'Inventory','title'=>'Inventory <script>','scope'=>'tenant','fields'=>[
 ['name'=>'title','label'=>'Title','type'=>'text','required'=>true],
 ['name'=>'quantity','label'=>'Quantity','type'=>'integer','required'=>false],
 ['name'=>'price','label'=>'Price','type'=>'decimal','required'=>false],
 ['name'=>'active','label'=>'Active','type'=>'boolean','required'=>true],
]];
$gen=new ModuleScaffolder($root);$plan=$gen->plan($schema);
check(count($plan['files'])>=7&&!is_dir($root.'/Inventory'),'module preview does not create files');
check(str_contains($plan['sql'],'`tenant_id` BIGINT UNSIGNED NOT NULL'),'tenant migration includes tenant boundary');
try{$gen->apply($schema,str_repeat('0',64));check(false,'tampered confirmation token accepted');}catch(RuntimeException){check(!is_dir($root.'/Inventory'),'bad confirmation does not write module files');}
$gen->apply($schema,$plan['digest']);
check(is_file($root.'/Inventory/Models/RecordRepository.php')&&is_file($root.'/Inventory/Controllers/CrudController.php'),'generated MVC files are published');
check(ModuleBlueprint::fromFile($root.'/Inventory/blueprint.json')===ModuleBlueprint::normalize($schema),'generated schema roundtrips');
foreach(['routes.php','Models/RecordRepository.php','Controllers/CrudController.php'] as $file){
    $output=[];$code=0;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($root.'/Inventory/'.$file).' 2>&1',$output,$code);
    check($code===0,'generated PHP passes lint: '.$file);
}
$manager=new ModuleManager($root.'/state.json',$root);
check(in_array('Inventory',$manager->validate(),true),'generated module passes dependency graph');
$activate=$manager->planChange('Inventory',true);$manager->setEnabled('Inventory',true,$activate['token']);
$temporaryAutoload=static function(string $class)use($root):void{if(str_starts_with($class,'Modules\\Inventory\\')){ $suffix=substr($class,strlen('Modules\\Inventory\\'));$path=$root.'/Inventory/'.str_replace('\\','/',$suffix).'.php';if(is_file($path))require_once $path; }};
spl_autoload_register($temporaryAutoload);
$routes=new Router();$manager->register($routes);
spl_autoload_unregister($temporaryAutoload);$paths=array_column($routes->routes(),'path');
check(in_array('/workspace/inventory',$paths,true)&&in_array('/api/v1/modules/inventory/{id}',$paths,true),'generated UI and API routes register');
$repo=new CrudRepository(new Database(),new TenantRecords(new Database(),new ContextStore()),ModuleBlueprint::normalize($schema));
check($repo->validated(['title'=>'Acme','quantity'=>'21','price'=>'12.50','active'=>'1'])['price']==='12.50','generated repo validates typed inputs');
check($repo->validated(['title'=>'Acme','active'=>'0'])['active']===0,'generated repo validates zero boolean');
foreach([
 ['title'=>'','active'=>'1'],
 ['title'=>'X','quantity'=>'1 OR 1=1','active'=>'1'],
 ['title'=>'X','price'=>'1e309','active'=>'1'],
 ['title'=>'X','active'=>'5'],
 ['title'=>'X','tenant_id'=>99,'active'=>'1'],
 ['title'=>['bad'],'active'=>'1']
] as $data){try{$repo->validated($data);check(false,'unsafe repo input accepted');}catch(InvalidArgumentException){check(true,'unsafe repo field rejected');}}
try{$gen->plan($schema);check(false,'generator accepted overwrite');}catch(RuntimeException){check(true,'generator refuses overwriting existing modules');}
foreach([
 ['name'=>'Evil\\Injected','title'=>'No','scope'=>'tenant','fields'=>[['name'=>'x','label'=>'x','type'=>'text']]],
 ['name'=>'Orders','title'=>'Ok','scope'=>'tenant','fields'=>[['name'=>'id','label'=>'ID','type'=>'text']]],
 ['name'=>'Orders','title'=>'Ok','scope'=>'tenant','fields'=>[['name'=>'valid','label'=>'x','type'=>'php']]],
 ['name'=>'Orders','title'=>'Ok','scope'=>'tenant','fields'=>[['name'=>'same','label'=>'A','type'=>'text'],['name'=>'same','label'=>'B','type'=>'email']]],
 ['name'=>'Orders','title'=>'Ok','scope'=>'tenant','fields'=>[['name'=>'x','label'=>'<x>','type'=>'text']],'php'=>'arbitrary'],
] as $bad){try{ModuleBlueprint::normalize($bad);check(false,'invalid blueprint accepted');}catch(InvalidArgumentException){check(true,'unsafe blueprint rejected');}}
$central=['name'=>'Ledger','title'=>'Ledger','scope'=>'central','fields'=>[['name'=>'memo','label'=>'Memo','type'=>'textarea','required'=>false]]];
$plan2=$gen->plan($central);
check(str_contains($plan2['sql'],"'module.ledger.read'")&&!str_contains($plan2['sql'],'`tenant_id`'),'central migration provides permissions without tenant column');
$gen->apply($central,$plan2['digest']);
check(str_contains(file_get_contents($root.'/Ledger/routes.php'),'module.ledger.write'),'central generated routes enforce distinct write permission');
check(\Brightery\Core\Api\TokenService::validScopes(['modules:read','modules:write']),'API token scopes permit controlled module operations');
check(\Brightery\Core\App::get()->service(\Brightery\Core\Ui\AssetManager::class)->url('js/module-builder.js')!=='/assets/js/module-builder.js','module builder JavaScript is fingerprinted');
foreach(glob($root.'/*') as $dir){if(is_dir($dir)){
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($it as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($dir);
}else unlink($dir);}
foreach(glob($root.'/.*') as $hidden){if(is_file($hidden))unlink($hidden);}
rmdir($root);
