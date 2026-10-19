<?php
declare(strict_types=1);
use Brightery\Core\RateBuckets;
use Brightery\Core\Mfa\RecoveryCodes;
use Brightery\Core\Auth\SessionRegistry;
use Brightery\Core\Mail\{SmtpTransport,EmailVerification};
use Brightery\Core\Media\PrivateMedia;
use Brightery\Core\Database\Migrator;
use Brightery\Core\Jobs\Registry;
use Brightery\Core\Kernel;
use Brightery\Core\Http\Request;
check(version_compare(Kernel::VERSION,'0.5.0','>='),'framework version 0.5.0');
check(RateBuckets::key(null,'api.authenticated','123')!==RateBuckets::key(100,'api.authenticated','123'),'rate buckets isolated by tenant');
check(RateBuckets::key(10,'api.authenticated','123')!==RateBuckets::key(10,'api.authenticated','124'),'rate buckets isolated by actor');
try{RateBuckets::key(10,'api/authenticated','abc');check(false,'unsafe rate category accepted');}
catch(InvalidArgumentException){check(true,'unsafe rate category rejected');}
$recoveryHex=bin2hex(random_bytes(16));$formatted=RecoveryCodes::format($recoveryHex);
check(RecoveryCodes::digest($formatted)===hash('sha256',$recoveryHex),'recovery code normalization and digest');
check(RecoveryCodes::digest('bad-code')===null,'malformed MFA recovery code rejected');
check(SessionRegistry::digest('sess-abc')===hash('sha256','sess-abc'),'session inventory stores only hashes');
try{SessionRegistry::digest('');check(false,'empty session accepted');}catch(InvalidArgumentException){check(true,'session inventory rejects empty id');}
check(EmailVerification::tokenValid(bin2hex(random_bytes(32)))&&!EmailVerification::tokenValid('x'.str_repeat('0',64)),'email verification one-time token syntax');
$mail=SmtpTransport::message('sender@example.org','reader@example.org','مرحبا',"First\n.Second\r\nEnd");
check(str_contains($mail,'Content-Type: text/plain; charset=UTF-8')&&str_contains($mail,"\r\n..Second\r\n"),'email wire format and SMTP dot-stuffing');
foreach(['subject' => "Test\r\nBcc: bad@example.org",'recipient'=>"victim@example.org\r\nBcc: spy@example.org"] as $field=>$injection){
    try{
        if($field==='subject')SmtpTransport::message('sender@example.org','reader@example.org',$injection,'Text');
        else SmtpTransport::message('sender@example.org',$injection,'Subject','Text');
        check(false,'mail header injection accepted');
    }catch(RuntimeException){check(true,'mail '.$field.' header injection rejected');}
}
$file=tempnam(sys_get_temp_dir(),'bf-media');file_put_contents($file,'<?php echo "evil";');
try{PrivateMedia::validated($file,'evil.php');check(false,'executable upload allowed');}
catch(RuntimeException){check(true,'executable upload MIME rejected');}
@unlink($file);
$privatePNG=tempnam(sys_get_temp_dir(),'bf-png');
file_put_contents($privatePNG,base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7OPR8AAAAASUVORK5CYII='));
try{[$m,$size,$name]=PrivateMedia::validated($privatePNG,'avatar.png');check($m==='image/png'&&$size>0&&$name==='avatar.png','real PNG accepted with MIME sniffing');}
finally{@unlink($privatePNG);}
check(count(Migrator::statements(file_get_contents(base_path('database/migrations/005_identity_delivery_media.sql'))))===7,'v0.5 seven additive SQL tables');
$routes=array_column($kernel->router->routes(),'path');
foreach(['/account/security','/account/security/email/send','/verify-email/{token}','/admin/media/private','/workspace/media','/account/mfa/recovery/rotate'] as $route)check(in_array($route,$routes,true),'v0.5 route registered: '.$route);
$jobs=$kernel->service(Registry::class);
check($jobs->has('system.mail.send')&&$jobs->has('system.security.prune'),'v0.5 trusted background handlers registered');
check($kernel->handle(new Request('GET','/account/security',server:['HTTP_HOST'=>'localhost']))->status===401,'anonymous security page denied');
check($kernel->handle(new Request('POST','/verify-email/'.str_repeat('a',64),server:['HTTP_HOST'=>'localhost']))->status===419,'email link confirmation requires CSRF');
