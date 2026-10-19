<?php
declare(strict_types=1);
use Brightery\Core\Developer\{ModuleBlueprint as BP16,ModuleScaffolder as SF16,ModuleEvolver as EV16,CrudRepository as CR16};
use Brightery\Core\Database as DB16;
use Brightery\Core\Tenancy\{TenantRecords as TR16,ContextStore as CT16};
check(\Brightery\Core\Kernel::VERSION==='1.6.1','v1.6 framework version');
$root16=sys_get_temp_dir().'/bf-v16-'.bin2hex(random_bytes(8));mkdir($root16,0700,true);
try{
    $builder16=new SF16($root16);
    $target16=['name'=>'SharedTags','title'=>'Shared tags','scope'=>'tenant','soft_delete'=>true,'fields'=>[['name'=>'title','label'=>'Name','type'=>'text','required'=>true]]];
    $plan16=$builder16->plan($target16);$builder16->apply($target16,$plan16['digest']);
    $source16=['name'=>'TaggedPosts','title'=>'Tagged posts','scope'=>'tenant','fields'=>[['name'=>'title','label'=>'Title','type'=>'text','required'=>true],['name'=>'tags','label'=>'Tags','type'=>'many_to_many','references'=>'SharedTags','required'=>false]]];
    $canon16=BP16::normalize($source16);
    check($canon16['fields'][1]['type']==='many_to_many','v1.6 many-to-many schema normalized');
    check(BP16::pivotFor('TaggedPosts','tags')===BP16::pivotFor('TaggedPosts','tags')&&strlen(BP16::pivotFor('TaggedPosts','tags'))<64,'v1.6 pivot name deterministic and MySQL-safe');
    $plan16=$builder16->plan($source16);
    $pivot16=BP16::pivotFor('TaggedPosts','tags');
    check(str_contains($plan16['sql'],'CREATE TABLE IF NOT EXISTS `'.$pivot16.'`')&&!str_contains($plan16['sql'],'`tags` VARCHAR'),'v1.6 multi-value fields use separate join tables');
    check(str_contains($plan16['sql'],'ON DELETE CASCADE')&&str_contains($plan16['sql'],'`tenant_id` BIGINT UNSIGNED NOT NULL'),'v1.6 pivot has foreign key cleanup and tenant column');
    $builder16->apply($source16,$plan16['digest']);
    $manifest16=json_decode(file_get_contents($root16.'/TaggedPosts/module.json'),true,16,JSON_THROW_ON_ERROR);
    check(isset($manifest16['dependencies']['SharedTags']),'v1.6 generated module depends on relation target');
    $routes16=(string)file_get_contents($root16.'/TaggedPosts/routes.php');
    check(str_contains($routes16,'/export')&&str_contains($routes16,'/bulk-delete')&&str_contains($routes16,'[$read]'),'v1.6 export and data actions protected by route guards');
    foreach(['Models/RecordRepository.php','Controllers/CrudController.php','Policies/RecordPolicy.php','routes.php'] as $file16){
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($root16.'/TaggedPosts/'.$file16).' 2>&1',$out16,$exit16);
        check($exit16===0,'v1.6 generated PHP syntax: '.$file16);unset($out16,$exit16);
    }
    $repo16=new CR16(new DB16(),new TR16(new DB16(),new CT16()),$canon16,null,$root16);
    check($repo16->validated(['title'=>'Alpha','tags'=>[]])['tags']===[],'v1.6 empty relationship set accepted');
    foreach([[0],['1','1'],['1 OR 1=1'],range(1,51),['-4'],[''],[1.5]] as $bad16){
        try{$repo16->validated(['title'=>'Alpha','tags'=>$bad16]);check(false,'v1.6 invalid relationship accepted');}
        catch(InvalidArgumentException){check(true,'v1.6 unsafe relationship rejected before DB');}
    }
    try{$repo16->page(['sort'=>'tags']);check(false,'v1.6 virtual relationship used as SQL sort');}
    catch(InvalidArgumentException){check(true,'v1.6 virtual relationship forbidden as SQL sort');}
    try{$repo16->page(['filter'=>'tags','value'=>'1']);check(false,'v1.6 virtual relationship used as SQL filter');}
    catch(InvalidArgumentException){check(true,'v1.6 virtual relationship forbidden as SQL filter');}
    foreach([['tags'=>[1,1]],['tags'=>range(1,51)]] as $bad16){
        try{$repo16->validated($bad16,true);check(false,'v1.6 partial relation accepts invalid IDs');}
        catch(InvalidArgumentException){check(true,'v1.6 PATCH relationship validation bounded');}
    }
    $invalid16=$source16;$invalid16['fields'][1]['required']=true;
    try{BP16::normalize($invalid16);check(false,'v1.6 required multi relationship allowed');}
    catch(InvalidArgumentException){check(true,'v1.6 disallows unsupported required relationship');}
    $invalid16=$source16;$invalid16['fields'][1]['rules']=['min'=>1];
    try{BP16::normalize($invalid16);check(false,'v1.6 numeric rules allowed on relationship');}
    catch(InvalidArgumentException){check(true,'v1.6 rejects scalar rules on relationship');}
    $badRef16=$source16;$badRef16['fields'][1]['references']='UnknownModule';
    try{$builder16->plan($badRef16);check(false,'v1.6 unknown referenced module accepted');}
    catch(RuntimeException){check(true,'v1.6 relation references must exist');}
    $changed16=$source16;$changed16['fields'][]=['name'=>'comments','label'=>'Commenting users','type'=>'many_to_many','references'=>'SharedTags','required'=>false];
    $evolver16=new EV16($root16);$evolve16=$evolver16->plan('TaggedPosts',$changed16);
    check(str_contains($evolve16['sql'],'CREATE TABLE IF NOT EXISTS `'.BP16::pivotFor('TaggedPosts','comments').'`')&&!str_contains($evolve16['sql'],'ADD COLUMN `comments`'),'v1.6 additive evolution creates join table instead of invalid column');
    try{$evolver16->apply('TaggedPosts',$changed16,str_repeat('0',64));check(false,'v1.6 stale relationship evolution applied');}
    catch(RuntimeException){check(true,'v1.6 stale relationship evolution rejected');}
    $central16=['name'=>'PlatformTags','title'=>'Platform tags','scope'=>'central','fields'=>[['name'=>'name','label'=>'Name','type'=>'text','required'=>true]]];
    $plan16=$builder16->plan($central16);$builder16->apply($central16,$plan16['digest']);
    $cross16=$source16;$cross16['fields'][1]['references']='PlatformTags';
    try{$builder16->plan($cross16);check(false,'v1.6 cross-scope relationship allowed');}
    catch(RuntimeException){check(true,'v1.6 cross-scope relationships rejected');}
    $ui16=(string)file_get_contents(base_path('themes/admin/generated-crud.php'));
    check(str_contains($ui16,'data-bf-multi-relation')&&str_contains($ui16,'/export')&&str_contains($ui16,'bulk-restore'),'v1.6 grid offers relationship chips, CSV export and bulk restore');
    $js16=(string)file_get_contents(base_path('public/assets/js/crud-controls.js'));
    check(str_contains($js16,'data-bf-multi-relation')&&str_contains($js16,'createElement')&&!str_contains($js16,'innerHTML'),'v1.6 multi-control uses DOM construction instead of HTML injection');
}finally{
    $walk16=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root16,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($walk16 as $entry16){$entry16->isDir()?rmdir($entry16->getPathname()):unlink($entry16->getPathname());}rmdir($root16);
}
