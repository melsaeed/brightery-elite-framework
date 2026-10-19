<?php
declare(strict_types=1);
use Brightery\Core\Developer\{ModuleBlueprint as Blueprint15,ModuleScaffolder as Scaffolder15,CrudRepository as Repo15,ModuleDiagnostics as Diagnostic15};
use Brightery\Core\Database as DB15;
use Brightery\Core\Tenancy\{TenantRecords as Records15,ContextStore as Context15};
use Brightery\Core\Http\Request as Request15;
check(version_compare(\Brightery\Core\Kernel::VERSION,'1.5.0','>='),'v1.5 compatibility baseline');
$root15=sys_get_temp_dir().'/bf-v15-'.bin2hex(random_bytes(8));mkdir($root15,0700,true);
$spec15=['name'=>'SamplePosts','title'=>'Sample Posts','scope'=>'tenant','soft_delete'=>true,'fields'=>[
    ['name'=>'title','label'=>'Title','type'=>'text','required'=>true,'rules'=>['min_length'=>3,'max_length'=>80]],
    ['name'=>'priority','label'=>'Priority','type'=>'integer','required'=>false,'rules'=>['min'=>1,'max'=>10]],
]];
$normalized15=Blueprint15::normalize($spec15);
check($normalized15['soft_delete']===true&&$normalized15['fields'][0]['rules']['min_length']===3,'soft delete and validation rules normalized');
$generator15=new Scaffolder15($root15);$plan15=$generator15->plan($spec15);
check(str_contains($plan15['sql'],'`deleted_at` TIMESTAMP NULL')&&str_contains($plan15['sql'],'idx_tenant_active'),'soft-delete migration adds nullable timestamp and scope index');
$generator15->apply($spec15,$plan15['digest']);
check(Blueprint15::fromFile($root15.'/SamplePosts/blueprint.json')===$normalized15,'soft-delete schema roundtrip');
$source15=file_get_contents($root15.'/SamplePosts/routes.php');
check(str_contains($source15,"/workspace/sample-posts/restore")&&str_contains($source15,"[\$write]"),'restore route generated with write permission');
$diagnostic15=(new Diagnostic15($root15))->inspect('SamplePosts');
check($diagnostic15['ok']&&$diagnostic15['failed']===0,'module diagnostics verifies generated files');
$repo15=new Repo15(new DB15(),new Records15(new DB15(),new Context15()),$normalized15);
check($repo15->softDeleteEnabled()&&$repo15->validated(['title'=>'ABC','priority'=>'5'])['priority']==='5','optional soft deletion and valid bounds');
foreach([['title'=>'x','priority'=>'5'],['title'=>str_repeat('x',81),'priority'=>'5'],['title'=>'Valid','priority'=>'0'],['title'=>'Valid','priority'=>'11']] as $bad15){
    try{$repo15->validated($bad15);check(false,'invalid field bound accepted');}
    catch(InvalidArgumentException){check(true,'field length and numeric rules reject invalid inputs');}
}
$legacy15=['name'=>'LegacyPosts','title'=>'Legacy posts','scope'=>'central','fields'=>[['name'=>'title','label'=>'Title','type'=>'text','required'=>true]]];
$legacyNormalized15=Blueprint15::normalize($legacy15);
check(!array_key_exists('soft_delete',$legacyNormalized15),'pre-1.5 blueprint remains identical');
$legacyRepo15=new Repo15(new DB15(),new Records15(new DB15(),new Context15()),$legacyNormalized15);
try{$legacyRepo15->page(['view'=>'trash']);check(false,'trash exposed on legacy module');}
catch(InvalidArgumentException){check(true,'legacy module rejects unsupported trash view');}
try{$legacyRepo15->restore(1);check(false,'legacy module restored without opt in');}
catch(InvalidArgumentException){check(true,'legacy module denies restore');}
foreach([
    ['name'=>'InvalidPosts','title'=>'Invalid','scope'=>'central','soft_delete'=>false,'fields'=>$legacy15['fields']],
    ['name'=>'InvalidPosts','title'=>'Invalid','scope'=>'central','fields'=>[['name'=>'title','label'=>'Title','type'=>'text','required'=>true,'rules'=>['regex'=>'.*']]]],
    ['name'=>'InvalidPosts','title'=>'Invalid','scope'=>'central','fields'=>[['name'=>'title','label'=>'Title','type'=>'text','required'=>true,'rules'=>['min_length'=>10,'max_length'=>2]]]],
    ['name'=>'InvalidPosts','title'=>'Invalid','scope'=>'central','fields'=>[['name'=>'title','label'=>'Title','type'=>'text','required'=>true,'rules'=>['max_length'=>9000]]]],
] as $invalid15){try{Blueprint15::normalize($invalid15);check(false,'invalid schema accepted');}catch(InvalidArgumentException){check(true,'dangerous or incompatible schema rejected');}}
$diagPaths15=array_column($kernel->router->routes(),'path');
check(in_array('/admin/developer/diagnostics',$diagPaths15,true),'diagnostics admin route registered');
$unauth15=$kernel->handle(new Request15('GET','/admin/developer/diagnostics',[],[],[],['HTTP_HOST'=>'localhost']));
check(in_array($unauth15->status,[302,303],true),'module diagnostics restricted to admins');
check(str_contains(file_get_contents(base_path('themes/admin/generated-crud.php')),'view=trash'),'CRUD view exposes opt-in trash navigation');
foreach(['routes.php','Models/RecordRepository.php','Controllers/CrudController.php'] as $relative){$exit15=0;$output15=[];exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($root15.'/SamplePosts/'.$relative).' 2>&1',$output15,$exit15);check($exit15===0,'generated v1.5 PHP syntax: '.$relative);}
$walk15=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root15,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($walk15 as $item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());}rmdir($root15);
