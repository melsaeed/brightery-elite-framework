#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use Brightery\Core\Operations\ProductionReadiness as P;
$tests=0;
$assert=static function(bool $value,string $note)use(&$tests):void {if(!$value)throw new RuntimeException('FAIL: '.$note);$tests++;echo "PASS: $note\n";};
$good=[
 'APP_ENV'=>'production','APP_DEBUG'=>'false','APP_KEY'=>bin2hex(random_bytes(32)),
 'APP_URL'=>'https://framework.example.com','SESSION_SECURE'=>'true','SESSION_REGISTRY_ENABLED'=>'true',
 'TRUSTED_HOSTS'=>'framework.example.com','TENANCY_MODE'=>'single','MAIL_DRIVER'=>'smtp',
 'SMTP_HOST'=>'smtp.example.com','MEDIA_SCAN_DRIVER'=>'clamav','MEDIA_SCAN_REQUIRED'=>'true'
];
$assert(P::configuration($good)===[],'production reference config passes');
$variants=[
 'debug'=>['APP_DEBUG'=>'true'], 'unsafe_key'=>['APP_KEY'=>'REPLACE_ME'],
 'insecure_site'=>['APP_URL'=>'http://framework.example.com'], 'host_spoof'=>['TRUSTED_HOSTS'=>'evil.example.com'],
 'missing_cookie_tlS'=>['SESSION_SECURE'=>'false'], 'unsafe_media'=>['MEDIA_SCAN_DRIVER'=>'disabled'],
 'maintenance'=>['MODULE_BUILDER_MAINTENANCE'=>'true'], 'unrevocable_session'=>['SESSION_REGISTRY_ENABLED'=>'false'],
 'invalid_mode'=>['TENANCY_MODE'=>'several'], 'logging_email'=>['MAIL_DRIVER'=>'log'],
 'duplicate_host'=>['TRUSTED_HOSTS'=>'framework.example.com,framework.example.com'],
];
foreach($variants as $title=>$changes)$assert(P::configuration(array_merge($good,$changes))!==[],'reject '.$title);
$multi=$good;$multi['TENANCY_MODE']='multi';
$assert(P::configuration($multi)!==[],'reject missing control host in multi-tenancy');
$multi['TENANCY_CONTROL_HOST']='framework.example.com';
$assert(P::configuration($multi)===[],'accept trusted tenant control hostname');
$runtime=P::system(dirname(__DIR__));
$assert(is_array($runtime),'check runtime dependencies without side effects');
echo "Production safety checks passed: $tests\n";
