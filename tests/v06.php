<?php
declare(strict_types=1);
use Brightery\Core\Form\{Fields,ControlRegistry};
use Brightery\Core\Media\ImageVariants;
use Brightery\Core\Mail\PasswordReset;
use Brightery\Core\Kernel;
use Brightery\Core\Http\Request;
use Brightery\Core\Database\Migrator;
check(version_compare(Kernel::VERSION,'0.6.0','>='),'v0.6 framework release identity');
check(PasswordReset::validToken(str_repeat('a',64))&&!PasswordReset::validToken(str_repeat('g',64)),'password reset token format');
check(PasswordReset::validOrigin('https://tenant.example.test')&&!PasswordReset::validOrigin('https://evil.test@tenant.example.test')&&!PasswordReset::validOrigin('http://evil.test'),'password reset origin refuses unsafe inputs');
check(ImageVariants::mode('cover')==='cover'&&ImageVariants::mode('contain')==='contain','image transformation modes');
try{ImageVariants::mode('fill');check(false,'arbitrary crop mode accepted');}catch(RuntimeException){check(true,'invalid image crop mode blocked');}
foreach([[10001,50,100,100,1000],[3000,3000,2410,100,1000],[400,400,100,100,20000000]] as $dims){
    try{ImageVariants::dimensions(...$dims);check(false,'unsafe image dimensions accepted');}catch(RuntimeException){check(true,'unsafe image sizing rejected');}
}
ImageVariants::dimensions(1000,800,320,320,1048576);check(true,'bounded image transforms accepted');
$registry=new ControlRegistry();
$escaped=$registry->render('text',['name'=>'safe','label'=>'A & B','value'=>'\"><script>alert(1)</script>']);
check(str_contains($escaped,'A &amp; B')&&str_contains($escaped,'&lt;script&gt;')&&!str_contains($escaped,'<script>'),'control registry escapes untrusted values');
$registry->register('custom-alert',static fn(array $p):string=>'<p>'.esc($p['message']??'').'</p>');
check($registry->render('custom-alert',['message'=>'<&'])==='<p>&lt;&amp;</p>','framework UI factories can be overridden in application modules');
$picker=Fields::files('documents','Documents',true,['application/pdf'],'/workspace/media/library');
check(str_contains($picker,'data-bf-open-library')&&str_contains($picker,'documents_existing_id'),'file picker can select private, permission-guarded existing media');
try{Fields::files('documents','Documents',true,['application/pdf'],'//evil.test/list');check(false,'cross-origin file library allowed');}
catch(InvalidArgumentException){check(true,'file picker requires a same-origin API path');}
$autocomplete=Fields::autocompleteSelect('customer_id','Customer','/admin/autocomplete');
check(str_contains($autocomplete,'name="customer_id"')&&str_contains($autocomplete,'data-autocomplete-id-mode'),'autocomplete ID field distinct from display label');
check(count(Migrator::statements(file_get_contents(base_path('database/migrations/006_media_password.sql'))))===2,'additive media/password migration parsed');
$routes=array_column($kernel->router->routes(),'path');
foreach(['/forgot-password','/reset-password/{token}','/admin/media/private/transform','/workspace/media/transform','/admin/media/private/library','/workspace/media/library','/workspace/media/variant/{id}','/admin/media/private/delete'] as $path)check(in_array($path,$routes,true),'v0.6 route registered: '.$path);
check($kernel->handle(new Request('GET','/forgot-password',server:['HTTP_HOST'=>'localhost']))->status===200,'anonymous password recovery form available');
check($kernel->handle(new Request('GET','/reset-password/invalid',server:['HTTP_HOST'=>'localhost']))->status===404,'invalid recovery token denied before DB call');
check($kernel->handle(new Request('GET','/reset-password/'.str_repeat('e',64),server:['HTTP_HOST'=>'localhost']))->status===200,'valid-shape recovery link is a form without token leakage');
check($kernel->handle(new Request('POST','/reset-password/'.str_repeat('e',64),server:['HTTP_HOST'=>'localhost']))->status===419,'password reset action is CSRF-protected');
check($kernel->handle(new Request('GET','/admin/media/private/library',server:['HTTP_HOST'=>'localhost']))->status!==200,'private library denied to unauthenticated guests');
