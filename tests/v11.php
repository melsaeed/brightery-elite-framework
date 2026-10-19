<?php
declare(strict_types=1);
use Brightery\Core\Form\FormBlueprint;
use Brightery\Core\Modules\ModuleManager;
use Brightery\Core\Kernel;
check(version_compare(Kernel::VERSION,'1.1.0','>='),'v1.1 release version remains compatible');
$tmp=sys_get_temp_dir().'/bf-v11-'.bin2hex(random_bytes(7));
mkdir($tmp.'/modules/System',0700,true);mkdir($tmp.'/modules/Widget',0700,true);mkdir($tmp.'/private',0700,true);
file_put_contents($tmp.'/modules/System/module.json',json_encode(['name'=>'System','required'=>true,'version'=>'0.3.4']));
file_put_contents($tmp.'/modules/Widget/module.json',json_encode(['name'=>'Widget','version'=>'1.0.0','dependencies'=>['System'=>'^0.3.0']]));
$manager=new ModuleManager($tmp.'/private/state.json',$tmp.'/modules');
$plan=$manager->planChange('Widget',true);
check($plan['changed']&&$plan['target']&&preg_match('/^[a-f0-9]{64}$/D',$plan['token'])===1,'module activation preview is deterministic');
try{$manager->setEnabled('Widget',true,'');check(false,'empty confirmation should fail');}catch(RuntimeException){check(!$manager->enabled('Widget'),'invalid confirmation fails closed');}
$manager->setEnabled('Widget',true,$plan['token']);
check($manager->enabled('Widget'),'confirmed module plan activates');
try{$manager->setEnabled('Widget',false,$plan['token']);check(false,'stale plan should fail');}catch(RuntimeException){check($manager->enabled('Widget'),'stale module confirmation cannot overwrite new state');}
$disable=$manager->planChange('Widget',false);check(!$disable['target'],'deactivation preview valid');
$manager->setEnabled('Widget',false,$disable['token']);check(!$manager->enabled('Widget'),'confirmed deactivation works');
try{$manager->planChange('System',false);check(false,'required module deactivation planned');}catch(RuntimeException){check(true,'required modules reject deactivation preview');}
$forms=new FormBlueprint($tmp.'/private/forms');
$schema=['title'=>'<script>alert(1)</script> Contact','fields'=>[
 ['name'=>'email','label'=>'Email <img src=x onerror=alert(1)>','type'=>'email','required'=>true],
 ['name'=>'industry','label'=>'Industry','type'=>'select','required'=>true,'options'=>['retail'=>'Retail','saas'=>'SaaS']],
 ['name'=>'detail','label'=>'Details','type'=>'textarea','required'=>false],
 ['name'=>'image','label'=>'Upload','type'=>'file','required'=>false,'multiple'=>true],
 ['name'=>'lookup','label'=>'Find company','type'=>'autocomplete','required'=>false,'endpoint'=>'/admin/autocomplete']
]];
$forms->save('lead_capture',$schema);
check($forms->load('lead_capture')===$forms->normalize($schema),'form definitions persist in private storage');
$html=$forms->render($schema);
check(!str_contains($html,'<script>')&&!str_contains($html,'<img src=x')&&str_contains($html,'&lt;script&gt;'),'form titles and labels escape untrusted markup');
check(str_contains($html,'data-autocomplete-id-mode="1"'),'form AJAX selection uses stable ID input');
check($forms->validateSubmission($schema,['email'=>'bad','industry'=>'forged'])['industry'][0]==='Invalid value','select input validates against allowed values');
check(isset($forms->validateSubmission($schema,['email'=>'bad','industry'=>'retail'])['email']),'email control validates on server');
check($forms->validateSubmission($schema,['email'=>'valid@example.com','industry'=>'saas'])===[],'valid form submission accepted');
try{$forms->normalize(['title'=>'x','fields'=>[['name'=>'x','label'=>'x','type'=>'autocomplete','endpoint'=>'//evil.test/']]]);check(false,'external autocomplete accepted');}catch(InvalidArgumentException){check(true,'external AJAX autocomplete is rejected');}
try{$forms->save('bad/../../slug',$schema);check(false,'form traversal accepted');}catch(InvalidArgumentException){check(true,'invalid form slugs are rejected');}
try{$forms->normalize(['title'=>'x','fields'=>[['name'=>'x','label'=>'x','type'=>'text'],['name'=>'x','label'=>'y','type'=>'text']]]);check(false,'duplicate input accepted');}catch(InvalidArgumentException){check(true,'duplicate input names are rejected');}
try{$forms->normalize(['title'=>'x','fields'=>[['name'=>'x','label'=>'x','type'=>'html']]]);check(false,'HTML injection type accepted');}catch(InvalidArgumentException){check(true,'raw HTML control type is rejected');}
try{$forms->normalize(['title'=>'x','fields'=>[['name'=>'x','label'=>'x','type'=>'select','options'=>['yes'=>'Yes']]],'unexpected'=>'<script>']);check(false,'unknown schema property accepted');}catch(InvalidArgumentException){check(true,'unrecognized schema property rejected');}
check($forms->listing()===['lead_capture'],'saved forms are discoverable');
check(str_starts_with(\Brightery\Core\App::get()->service(\Brightery\Core\Ui\AssetManager::class)->url('js/form-studio.js'),'/assets/build/form-studio.'),'form editor assets are fingerprinted');
foreach(glob($tmp.'/modules/*/module.json') as $file)unlink($file);
foreach(glob($tmp.'/modules/*') as $dir)rmdir($dir);
foreach(glob($tmp.'/private/forms/*') as $file)unlink($file);
@unlink($tmp.'/private/forms/.lock');rmdir($tmp.'/private/forms');
foreach(glob($tmp.'/private/*') as $file)unlink($file);
rmdir($tmp.'/private');rmdir($tmp.'/modules');rmdir($tmp);
