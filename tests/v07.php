<?php
declare(strict_types=1);
use Brightery\Core\Kernel;
use Brightery\Core\Media\MediaQuota;
use Brightery\Core\Media\Scan\{ScannerInterface,DisabledScanner,ClamAvScanner};
use Brightery\Core\Mail\TemplateCatalog;
use Brightery\Core\Database\Migrator;
use Brightery\Core\Http\Request;
check(version_compare(Kernel::VERSION,'0.7.0','>='),'v0.7 release identifier');
$catalog=$kernel->service(TemplateCatalog::class);
$en=$catalog->render('password.reset',['url'=>'https://example.test/reset-password/abc'],'en');
$ar=$catalog->render('password.reset',['url'=>'https://example.test/reset-password/abc'],'ar');
check(str_contains($en['body'],'https://example.test/reset-password/abc')&&str_contains($ar['body'],'https://example.test/reset-password/abc'),'localized template values interpolated');
check(str_contains($ar['subject'],'إعادة')&&!str_contains($en['subject'],'إعادة'),'Arabic and English mail subjects distinct');
check(!str_contains($ar['body'],'{url}')&&!str_contains($en['body'],'{url}'),'mail templates contain no unresolved placeholders');
try{$catalog->render('tenant.invite',['role'=>'owner'],'en');check(false,'missing mail template data accepted');}catch(RuntimeException){check(true,'missing template variables blocked');}
try{$catalog->render('../password.reset',[],'en');check(false,'template path traversal accepted');}catch(RuntimeException){check(true,'mail template key cannot traverse paths');}
check(is_a($kernel->service(ScannerInterface::class),ScannerInterface::class),'scanner service registered with upgrade-safe container');
$scanner=new DisabledScanner();putenv('MEDIA_SCAN_REQUIRED=false');$scanner->scan(__FILE__);check(true,'disabled scanner explicit opt-out');
putenv('MEDIA_SCAN_REQUIRED=true');try{$scanner->scan(__FILE__);check(false,'scanner requirement ignored');}catch(RuntimeException){check(true,'scanner requirement fails closed without adapter');}finally{putenv('MEDIA_SCAN_REQUIRED=false');}
ClamAvScanner::verdict("stream: OK\0");check(true,'ClamAV zINSTREAM NUL terminated clean verdict accepted');
foreach(["stream: Eicar-Test-Signature FOUND\0","stream: error ERROR\0",'OK'] as $reply){
    try{ClamAvScanner::verdict($reply);check(false,'unsafe antivirus verdict accepted');}catch(RuntimeException){check(true,'antivirus fails closed on infection/transport error');}
}
check(MediaQuota::positiveLimit('2097152',100)===2097152&&MediaQuota::positiveLimit('-1',100)===100,'quota limits validate configuration');
check(MediaQuota::positiveLimit('not-a-number',1024)===1024,'invalid quota config defaults securely');
$quota=$kernel->service(MediaQuota::class);check($quota instanceof MediaQuota,'media quota service supports extension via container');
check($kernel->service(\Brightery\Core\Jobs\Registry::class)->has('system.media.thumbnail'),'thumbnail queue handler registered');
check(count(Migrator::statements(file_get_contents(base_path('database/migrations/007_media_quotas.sql'))))===4,'media quota migration is valid parsed SQL');
$paths=array_column($kernel->router->routes(),'path');
check(in_array('/admin/tenants/media-quota',$paths,true),'central admin quota configuration route registered');
check($kernel->handle(new Request('GET','/workspace/media',server:['HTTP_HOST'=>'localhost']))->status!==200,'tenant media dashboard blocked to guests');
check($kernel->handle(new Request('POST','/admin/tenants/media-quota',server:['HTTP_HOST'=>'localhost']))->status===419,'quota changes require CSRF token');
check($kernel->handle(new Request('GET','/admin/tenants',server:['HTTP_HOST'=>'localhost']))->status!==200,'central company controls blocked to guests');
