#!/usr/bin/env php
<?php
declare(strict_types=1);
/** Quick fail-closed HTTP checks; not a penetration test. */
ob_start();
$kernel=require dirname(__DIR__).'/bootstrap/app.php';
use Brightery\Core\Http\Request;
$checks=0;
$assert=static function(bool $pass,string $description)use(&$checks):void {
    if(!$pass)throw new RuntimeException('SECURITY FAILURE: '.$description);
    $checks++;echo "PASS: $description\n";
};
$host=['HTTP_HOST'=>'localhost'];
$normal=$kernel->handle(new Request('GET','/health',[],[],[],$host));
$assert($normal->status===200,'trusted health route');
$assert(($normal->headers['X-Frame-Options']??'')==='DENY','anti-framing header');
$assert(str_contains($normal->headers['Content-Security-Policy']??'','object-src \'none\''),'content security policy');
$assert(preg_match('/^[a-f0-9]{32}$/D',$normal->headers['X-Request-ID']??'')===1,'server-generated correlation ID');
$other=$kernel->handle(new Request('GET','/health',[],[],[],['HTTP_HOST'=>'example.attacker.test']));
$assert($other->status===400,'untrusted host rejected');
$api=$kernel->handle(new Request('GET','/api/v1/me',[],[],[],$host));
$assert($api->status===401&&($api->headers['Cache-Control']??'')==='private, no-store','API unauthorized and private');
$post=$kernel->handle(new Request('POST','/admin/modules/toggle',[],[],[],$host));
$assert($post->status===419&&($post->headers['Cache-Control']??'')==='private, no-store','CSRF and private admin');
$private=$kernel->handle(new Request('GET','/workspace/media',[],[],[],$host));
$assert(($private->headers['Cache-Control']??'')==='private, no-store','private workspace cache policy');
$assert($normal->headers['X-Request-ID']!==$api->headers['X-Request-ID'],'request IDs never reused');
$adminForms=$kernel->handle(new Request('GET','/admin/forms',[],[],[],$host));
$assert(in_array($adminForms->status,[302,303],true)&&($adminForms->headers['Cache-Control']??'')==='private, no-store','form studio requires admin login and is not cached');
$adminModulePlan=$kernel->handle(new Request('GET','/admin/modules/plan',['name'=>'Blog','enabled'=>'1'],[],[],$host));
$assert(in_array($adminModulePlan->status,[302,303],true),'module approval preview requires admin login');
$forgedSave=$kernel->handle(new Request('POST','/admin/forms/save',[],['name'=>'test','schema'=>'{}'],[],$host));
$assert($forgedSave->status===419,'form definition writes require CSRF');
$developer=$kernel->handle(new Request('GET','/admin/developer',[],[],[],$host));
$assert(in_array($developer->status,[302,303],true)&&($developer->headers['Cache-Control']??'')==='private, no-store','module builder requires signed-in platform permissions');
$forgedBuilder=$kernel->handle(new Request('POST','/admin/developer/apply',[],['blueprint'=>'{}'],[],$host));
$assert($forgedBuilder->status===419,'module builder rejects CSRF-less writes');
$anonymousApi=$kernel->handle(new Request('GET','/api/v1/modules/catalog',[],[],[],$host));
$assert($anonymousApi->status===401,'generated API namespace requires bearer authentication');
$evolution=$kernel->handle(new Request('GET','/admin/developer/evolve',[],[],[],$host));
$assert(in_array($evolution->status,[302,303],true)&&($evolution->headers['Cache-Control']??'')==='private, no-store','visual evolution requires signed-in platform privileges');
$forgedEvolution=$kernel->handle(new Request('POST','/admin/developer/evolve/apply',[],['module'=>'Blog','blueprint'=>'{}'],[],$host));
$assert($forgedEvolution->status===419,'schema evolution requires valid CSRF');
echo "SECURITY SMOKE PASSED ($checks checks). Does not constitute an independent security review.\n";
ob_end_flush();
