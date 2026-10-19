<?php
declare(strict_types=1);
namespace Brightery\Core\Operations;

/** Read-only, deterministic configuration checks. Never prints credentials. */
final class ProductionReadiness {
    /** @param array<string,string> $cfg Configuration values (not secret values in results). */
    public static function configuration(array $cfg): array {
        $get=static fn(string $name,string $fallback=''):string=>(string)($cfg[$name]??$fallback);
        $issues=[];
        $bad=static function(string $code,string $advice)use(&$issues):void{$issues[]=['code'=>$code,'recommendation'=>$advice];};
        if($get('APP_ENV')!=='production')$bad('environment','Use APP_ENV=production for public deployment.');
        if($get('APP_DEBUG')!=='false')$bad('debug','Set APP_DEBUG=false.');
        $key=$get('APP_KEY');
        if(!preg_match('/^[a-fA-F0-9]{64}$/D',$key)||strlen(count_chars($key,3))<8)$bad('application_key','Generate a strong 32-byte random APP_KEY using setup:key; retain it securely.');
        $url=parse_url($get('APP_URL'));
        if(!is_array($url)||($url['scheme']??null)!=='https'||empty($url['host'])||isset($url['user'])||isset($url['pass'])||isset($url['fragment']))$bad('https_url','Set APP_URL to your actual https:// hostname without credentials.');
        if($get('SESSION_SECURE')!=='true')$bad('secure_cookies','Require SESSION_SECURE=true behind TLS.');
        if($get('SESSION_REGISTRY_ENABLED')!=='true')$bad('session_registry','Require SESSION_REGISTRY_ENABLED=true so sessions can be revoked.');
        $trusted=array_values(array_filter(array_map('trim',explode(',',$get('TRUSTED_HOSTS')))));
        if(!$trusted||count($trusted)!==count(array_unique($trusted))||in_array('*',$trusted,true))$bad('trusted_hosts','Configure explicit unique trusted hostnames.');
        if(isset($url['host'])&&!in_array(strtolower($url['host']),array_map('strtolower',$trusted),true))$bad('app_host','Include the APP_URL host in TRUSTED_HOSTS.');
        foreach($trusted as $host){
            if($host===''||str_contains($host,'*')||!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$|^[a-z0-9]$/iD',$host)){
                $bad('invalid_trusted_host','Use exact DNS hostnames in TRUSTED_HOSTS.');break;
            }
        }
        if(!in_array($get('TENANCY_MODE'),['single','multi'],true))$bad('tenancy_mode','Use TENANCY_MODE=single or multi.');
        if($get('TENANCY_MODE')==='multi'){
            $control=strtolower($get('TENANCY_CONTROL_HOST'));
            if(!$control||!in_array($control,array_map('strtolower',$trusted),true))$bad('control_host','Trust an explicit central administration hostname.');
        }
        if(!in_array($get('MAIL_DRIVER'),['smtp','disabled'],true))$bad('mail_transport','Use SMTP for email workflows, or explicitly disable mail.');
        if($get('MAIL_DRIVER')==='smtp' && !$get('SMTP_HOST'))$bad('smtp_host','Set SMTP_HOST to the verified mail server.');
        if($get('MEDIA_SCAN_REQUIRED')!=='true'||$get('MEDIA_SCAN_DRIVER')!=='clamav')$bad('media_scanning','For public upload routes, enable MEDIA_SCAN_REQUIRED=true and MEDIA_SCAN_DRIVER=clamav.');
        foreach(['MODULE_MAINTENANCE','DEPLOY_MAINTENANCE','MEDIA_RECONCILE_MAINTENANCE','MODULE_BUILDER_MAINTENANCE','MAINTENANCE_MODE'] as $flag){
            if($get($flag,'false')!=='false')$bad('maintenance_flags','Disable all installation, schema, and repair maintenance flags after deployment.');
        }
        return $issues;
    }
    /** Environment-backed configuration check. */
    public static function check(): array {
        $names=['APP_ENV','APP_DEBUG','APP_KEY','APP_URL','SESSION_SECURE','SESSION_REGISTRY_ENABLED','TRUSTED_HOSTS','TENANCY_MODE','TENANCY_CONTROL_HOST','MAIL_DRIVER','SMTP_HOST','MEDIA_SCAN_REQUIRED','MEDIA_SCAN_DRIVER','MODULE_MAINTENANCE','DEPLOY_MAINTENANCE','MEDIA_RECONCILE_MAINTENANCE','MODULE_BUILDER_MAINTENANCE','MAINTENANCE_MODE'];
        $config=[];foreach($names as $name)$config[$name]=(string)env($name,'');
        return self::configuration($config);
    }
    /** Runtime dependency / filesystem requirements. No credentials are emitted. */
    public static function system(string $projectRoot):array {
        $issues=[];
        foreach(['pdo_mysql','fileinfo','sodium','json','openssl','gd'] as $extension){
            if(!extension_loaded($extension))$issues[]=['code'=>'missing_extension_'.$extension,'recommendation'=>'Enable PHP extension '.$extension.' for PHP-FPM and CLI.'];
        }
        if(PHP_VERSION_ID<80400)$issues[]=['code'=>'php_version','recommendation'=>'Upgrade to supported PHP 8.4 or later.'];
        foreach(['storage','storage/private','storage/logs','storage/cache'] as $relative){
            if(!is_dir($projectRoot.'/'.$relative)||!is_writable($projectRoot.'/'.$relative))$issues[]=['code'=>'storage_'.$relative,'recommendation'=>'Create private writable '.$relative.' outside public/.'];
        }
        if(is_dir($projectRoot.'/public/storage'))$issues[]=['code'=>'public_storage','recommendation'=>'Do not expose private storage below public/.'];
        if(ini_get('display_errors') && !in_array(strtolower((string)ini_get('display_errors')),['0','off'],true))$issues[]=['code'=>'display_errors','recommendation'=>'Disable display_errors for production PHP-FPM.'];
        return $issues;
    }
}
