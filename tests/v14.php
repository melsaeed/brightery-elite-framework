<?php
declare(strict_types=1);
use Brightery\Core\Developer\{ModuleBlueprint,ModuleScaffolder,CrudRepository,CrudValidationPolicy,ModuleEvolver};
use Brightery\Core\Database;
use Brightery\Core\Tenancy\{TenantRecords,ContextStore};
use Brightery\Core\Http\Request;
check(version_compare(\Brightery\Core\Kernel::VERSION,'1.4.0','>='),'v1.4 compatibility baseline');
$root14=sys_get_temp_dir().'/bf-v14-'.bin2hex(random_bytes(8));mkdir($root14,0700,true);
$sc14=new ModuleScaffolder($root14);
$catalog14=['name'=>'GadgetCatalog','title'=>'Gadget Catalog','scope'=>'tenant','fields'=>[['name'=>'title','label'=>'Title','type'=>'text','required'=>true]]];
$plan14=$sc14->plan($catalog14);$sc14->apply($catalog14,$plan14['digest']);
$items14=['name'=>'GadgetItems','title'=>'Gadget Items','scope'=>'tenant','fields'=>[['name'=>'title','label'=>'Title','type'=>'text','required'=>true],['name'=>'catalog_id','label'=>'Catalog','type'=>'relation','required'=>false,'references'=>'GadgetCatalog']]];
$plan14=$sc14->plan($items14);
check(in_array('Policies/RecordPolicy.php',$plan14['files'],true),'scaffolder creates application-owned validation policy');
$sc14->apply($items14,$plan14['digest']);
$source14=(string)file_get_contents($root14.'/GadgetItems/routes.php');
check(str_contains($source14,"/workspace/gadget-items/relations")&&str_contains($source14,"/workspace/gadget-items/bulk-delete"),'module routes expose guarded relation and bulk operations');
check(str_contains($source14,'[$read]')&&str_contains($source14,'[$write]'),'generated relation and bulk routes require permission guards');
$repositorySource14=(string)file_get_contents($root14.'/GadgetItems/Models/RecordRepository.php');
check(str_contains($repositorySource14,'Policies\\RecordPolicy'),'generated repository loads its own policy');
foreach(['Models/RecordRepository.php','Controllers/CrudController.php','Policies/RecordPolicy.php','routes.php'] as $file){
    $output=[];$exit=0;exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($root14.'/GadgetItems/'.$file).' 2>&1',$output,$exit);
    check($exit===0,'generated 1.4 PHP syntax: '.$file);
}
$custom14=new class implements CrudValidationPolicy {
    public int $calls=0;
    public function validate(array $schema,array $values,bool $partial):void {
        $this->calls++;
        if(($values['title']??null)==='Forbidden')throw new InvalidArgumentException('Forbidden title');
    }
};
$repo14=new CrudRepository(new Database(),new TenantRecords(new Database(),new ContextStore()),ModuleBlueprint::normalize($items14),$custom14);
check($repo14->validated(['title'=>'Allowed','catalog_id'=>''])['title']==='Allowed'&&$custom14->calls===1,'custom validation policy runs on normalized values');
try{$repo14->validated(['title'=>'Forbidden','catalog_id'=>'']);check(false,'custom policy bypassed');}
catch(InvalidArgumentException){check($custom14->calls===2,'custom policy rejects business-invalid record');}
foreach([['sort'=>'`id`'],['sort'=>'tenant_id'],['direction'=>['ASC']],['direction'=>'UP'],['sort'=>[]],['page'=>'0'],['per_page'=>10000]] as $query){
 try{$repo14->page($query);check(false,'unsafe sort or pagination accepted');}catch(InvalidArgumentException){check(true,'unsafe paging/sorting rejected before SQL');}
}
foreach([[0],[1,1],range(1,51),['1 OR 1=1'],['0'],['1','1']] as $ids){
 try{$repo14->bulkDelete($ids);check(false,'unsafe bulk deletion accepted');}catch(InvalidArgumentException){check(true,'unsafe bulk selection rejected before SQL');}
}
foreach([['x', '',null],['catalog_id',str_repeat('a',81),null],['catalog_id','',0]] as [$field,$query,$selected]){
 try{$repo14->searchRelation($field,$query,$selected,20,$root14);check(false,'invalid relation search accepted');}catch(InvalidArgumentException){check(true,'relation lookup validates fields, bounds, ids');}
}
$next14=$items14;$next14['fields'][]=['name'=>'note','label'=>'Note','type'=>'textarea','required'=>false];
$evolver14=new ModuleEvolver($root14);$review14=$evolver14->plan('GadgetItems',$next14);
try{$evolver14->apply('GadgetItems',$next14,str_repeat('0',64));check(false,'stale evolve confirmation accepted');}
catch(RuntimeException){check(!is_file($root14.'/GadgetItems/'.$review14['migration']),'evolution preview leaves no migration without valid approval');}
$paths14=array_column($kernel->router->routes(),'path');
check(in_array('/admin/developer/evolve',$paths14,true)&&in_array('/admin/developer/evolve/apply',$paths14,true),'visual evolution routes are registered');
$unauthorized14=$kernel->handle(new Request('GET','/admin/developer/evolve',[],[],[],['HTTP_HOST'=>'localhost']));
check($unauthorized14->status===303||$unauthorized14->status===302,'visual evolution page requires authentication');
check(str_contains((string)file_get_contents(base_path('themes/admin/generated-crud.php')),'data-bf-relation'),'generated form uses AJAX relation selector');
check(str_contains((string)file_get_contents(base_path('themes/admin/generated-crud.php')),'data-bf-bulk'),'generated listing contains bounded bulk-action control');
check(str_contains(\Brightery\Core\App::get()->service(\Brightery\Core\Ui\AssetManager::class)->url('js/crud-controls.js'),'/assets/build/'),'new hybrid CRUD JavaScript is fingerprinted');
$walk14=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root14,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($walk14 as $item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());}rmdir($root14);
