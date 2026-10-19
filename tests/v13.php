<?php
declare(strict_types=1);
use Brightery\Core\Developer\{ModuleBlueprint,ModuleScaffolder,ModuleEvolver,CrudRepository};
use Brightery\Core\Tenancy\{ContextStore,TenantRecords};
use Brightery\Core\Database;
check(version_compare(\Brightery\Core\Kernel::VERSION,'1.3.0','>='),'v1.3 release version');
$root13=sys_get_temp_dir().'/bf-v13-'.bin2hex(random_bytes(8));mkdir($root13,0700,true);
$g13=new ModuleScaffolder($root13);
$parent13=['name'=>'LibraryBook','title'=>'Library Book','scope'=>'tenant','fields'=>[['name'=>'name','label'=>'Name','type'=>'text','required'=>true]]];
$p13=$g13->plan($parent13);$g13->apply($parent13,$p13['digest']);
$child13=['name'=>'LibraryLoan','title'=>'Library Loan','scope'=>'tenant','fields'=>[
 ['name'=>'description','label'=>'Description','type'=>'text','required'=>true],
 ['name'=>'book_id','label'=>'Book','type'=>'relation','required'=>false,'references'=>'LibraryBook'],
]];
$childPlan13=$g13->plan($child13);
check(str_contains($childPlan13['sql'],'`book_id` BIGINT UNSIGNED NULL'),'relation produces nullable BIGINT SQL');
$g13->apply($child13,$childPlan13['digest']);
check(ModuleBlueprint::fromFile($root13.'/LibraryLoan/blueprint.json')['fields'][1]['references']==='LibraryBook','relation metadata persists safely');
foreach([
 ['name'=>'Unused','title'=>'Unused','scope'=>'tenant','fields'=>[['name'=>'ref','label'=>'Ref','type'=>'relation','required'=>false,'references'=>'UnknownTarget']]],
 ['name'=>'Unused','title'=>'Unused','scope'=>'tenant','fields'=>[['name'=>'ref','label'=>'Ref','type'=>'relation','required'=>false,'references'=>'LibraryBook/../../x']]],
 ['name'=>'Unused','title'=>'Unused','scope'=>'tenant','fields'=>[['name'=>'ref','label'=>'Ref','type'=>'text','required'=>false,'references'=>'LibraryBook']]],
] as $invalid){try{$g13->plan($invalid);check(false,'unsafe relation accepted');}catch(\InvalidArgumentException|\RuntimeException){check(true,'unsafe or missing relation rejected');}}
$central13=['name'=>'AuditCards','title'=>'Audit Cards','scope'=>'central','fields'=>[['name'=>'summary','label'=>'Summary','type'=>'text','required'=>false]]];
$g13->apply($central13,$g13->plan($central13)['digest']);
try{$g13->plan(['name'=>'InvalidLink','title'=>'Invalid Link','scope'=>'tenant','fields'=>[['name'=>'idref','label'=>'Reference','type'=>'relation','required'=>false,'references'=>'AuditCards']]]);check(false,'cross-scope relation accepted');}catch(\RuntimeException){check(true,'cross-scope relation rejected');}
$manager13=new \Brightery\Core\Modules\ModuleManager($root13.'/state.json',$root13);
check(isset($manager13->migrationPathsFor('LibraryLoan')['module_LibraryLoan__001_create.sql'])&&!$manager13->enabled('LibraryLoan'),'disabled module migration remains discoverable');
$evolver13=new ModuleEvolver($root13);
$next13=$child13;$next13['fields'][]=['name'=>'notes','label'=>'Notes','type'=>'textarea','required'=>false];
$eplan13=$evolver13->plan('LibraryLoan',$next13);
check(str_contains($eplan13['sql'],'ALTER TABLE `bf_gen_library_loan` ADD COLUMN `notes` TEXT NULL'),'schema evolution SQL is additive');
try{$evolver13->apply('LibraryLoan',$next13,str_repeat('a',64));check(false,'incorrect review digest accepted');}catch(\RuntimeException){check(!is_file($root13.'/LibraryLoan/'.$eplan13['migration']),'stale evolution cannot write');}
$controllerBefore13=file_get_contents($root13.'/LibraryLoan/Controllers/CrudController.php');
$evolver13->apply('LibraryLoan',$next13,$eplan13['digest']);
check(is_file($root13.'/LibraryLoan/'.$eplan13['migration']) && count(ModuleBlueprint::fromFile($root13.'/LibraryLoan/blueprint.json')['fields'])===3,'evolution publishes schema and new migration');
check(isset($manager13->migrationPathsFor('LibraryLoan')['module_LibraryLoan__'.basename($eplan13['migration'])]),'disabled module exposes new additive migration for targeted runner');
check(file_get_contents($root13.'/LibraryLoan/Controllers/CrudController.php')===$controllerBefore13,'schema evolution preserves customized generated PHP');
foreach([
 (function()use($next13){$x=$next13;$x['fields'][0]['label']='Changed';return $x;})(),
 (function()use($next13){$x=$next13;$x['fields'][]=['name'=>'must_set','label'=>'Required','type'=>'text','required'=>true];return $x;})(),
 (function()use($next13){$x=$next13;array_pop($x['fields']);return $x;})(),
] as $bad){try{$evolver13->plan('LibraryLoan',$bad);check(false,'unsafe evolution accepted');}catch(\RuntimeException){check(true,'destructive evolution rejected');}}
$repo13=new CrudRepository(new Database(),new TenantRecords(new Database(),new ContextStore()),ModuleBlueprint::normalize($child13));
foreach([['page'=>'-1'],['per_page'=>'9999'],['filter'=>'tenant_id','value'=>'1'],['search'=>['invalid']],['filter'=>'book_id','value'=>'0'],['extra'=>'unsafe']] as $bad){
 try{$repo13->page($bad);check(false,'bad paging accepted');}catch(\InvalidArgumentException){check(true,'unsafe paging rejected before query');}
}
$iterator13=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root13,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
foreach($iterator13 as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($root13);
