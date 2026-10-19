<?php
declare(strict_types=1);
namespace Brightery\Core\Modules;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/** Ed25519-signed .bfp module envelope. No ZIP/extraction or executable deserialization. */
final class ModulePackage {
    public const FORMAT='brightery.module.v1';
    public const MAX_FILES=400;
    public const MAX_FILE_BYTES=4194304;
    public const MAX_TOTAL_BYTES=25165824;
    public const MAX_PACKAGE_BYTES=35000000;

    /** @return array<string,mixed> */
    public static function build(string $directory,string $keyId,string $privateKey):array {
        self::keyId($keyId);
        if(!is_dir($directory)||is_link($directory))throw new RuntimeException('Module directory must be real and non-symlinked');
        $name=basename(rtrim($directory,'/'));
        self::name($name);
        if(strlen($privateKey)!==SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)throw new RuntimeException('Invalid Ed25519 secret key length');
        $files=[];$total=0;
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS)) as $info){
            if($info->isLink()||!$info->isFile())throw new RuntimeException('Symlinks and non-files cannot be packaged');
            $relative=substr($info->getPathname(),strlen(rtrim($directory,'/'))+1);
            self::filePath($relative);
            $size=$info->getSize();
            if($size>self::MAX_FILE_BYTES||++$total>self::MAX_FILES)throw new RuntimeException('Module package file count or file size limit exceeded');
            $files[$relative]=base64_encode(self::fileBytes($info->getPathname()));
        }
        ksort($files,SORT_STRING);$files=self::checkedFiles($files);
        $manifest=self::manifest($files,$name);
        $payload=['format'=>self::FORMAT,'name'=>$name,'version'=>$manifest['version'],'files'=>$files];
        $signature=sodium_crypto_sign_detached(self::signedMessage($keyId,$payload),$privateKey);
        sodium_memzero($privateKey);
        return ['payload'=>$payload,'signature'=>['algorithm'=>'Ed25519','key_id'=>$keyId,'value'=>base64_encode($signature)]];
    }

    public static function encode(array $envelope):string {
        return json_encode($envelope,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    /** Decode and perform only structural checks; use verify() before installing. */
    public static function decode(string $json):array {
        if(strlen($json)>self::MAX_PACKAGE_BYTES)throw new RuntimeException('Module package exceeds size limit');
        $envelope=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($envelope)||array_keys($envelope)!==['payload','signature'])throw new RuntimeException('Invalid module envelope');
        $payload=$envelope['payload'];$sig=$envelope['signature'];
        if(!is_array($payload)||array_keys($payload)!==['format','name','version','files']||($payload['format']??null)!==self::FORMAT)throw new RuntimeException('Unsupported module package format');
        self::name($payload['name']);
        if(!is_string($payload['version'])||!preg_match('/^\d+\.\d+\.\d+(?:-[a-z0-9.-]+)?$/Di',$payload['version']))throw new RuntimeException('Invalid module version');
        $files=self::checkedFiles($payload['files']);
        $manifest=self::manifest($files,$payload['name']);
        if($payload['version']!==$manifest['version'])throw new RuntimeException('Version does not match module manifest');
        if(!is_array($sig)||array_keys($sig)!==['algorithm','key_id','value']||$sig['algorithm']!=='Ed25519'||!is_string($sig['value']))throw new RuntimeException('Invalid signature envelope');
        self::keyId($sig['key_id']);
        $signature=base64_decode($sig['value'],true);
        if($signature===false||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES||base64_encode($signature)!==$sig['value'])throw new RuntimeException('Invalid Ed25519 signature encoding');
        return $envelope;
    }
    /** @param array<string,string> $trustedPublicKeys keyId => base64 public key */
    public static function verify(array $envelope,array $trustedPublicKeys):array {
        $envelope=self::decode(self::encode($envelope));
        $keyId=$envelope['signature']['key_id'];
        if(!array_key_exists($keyId,$trustedPublicKeys))throw new RuntimeException('Untrusted module signing key: '.$keyId);
        $public=base64_decode((string)$trustedPublicKeys[$keyId],true);
        if($public===false||strlen($public)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)throw new RuntimeException('Invalid trusted public key');
        $signature=base64_decode($envelope['signature']['value'],true);
        if(!sodium_crypto_sign_verify_detached($signature,self::signedMessage($keyId,$envelope['payload']),$public))throw new RuntimeException('Module package signature verification failed');
        return $envelope['payload'];
    }
    public static function signedMessage(string $keyId,array $payload):string {
        return "BRIGHTERY-MODULE-PACKAGE-V1\n".$keyId."\n".self::encode($payload);
    }
    public static function name(mixed $name):void {
        if(!is_string($name)||!preg_match('/^[A-Z][A-Za-z0-9]*$/D',$name))throw new RuntimeException('Invalid module name');
    }
    public static function keyId(mixed $keyId):void {
        if(!is_string($keyId)||!preg_match('/^[a-zA-Z][a-zA-Z0-9._-]{1,63}$/D',$keyId))throw new RuntimeException('Invalid signing key ID');
    }
    public static function filePath(mixed $path):void {
        if(!is_string($path)||$path===''||strlen($path)>230||str_contains($path,'\\')||str_contains($path,"\0")||str_starts_with($path,'/')||str_contains($path,'//'))throw new RuntimeException('Unsafe module file path');
        foreach(explode('/',$path) as $segment){
            if($segment===''||$segment==='.'||$segment==='..'||str_starts_with($segment,'.')||!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9_.-]*$/D',$segment))throw new RuntimeException('Unsafe module path segment');
        }
        if(count(explode('/',$path))>14)throw new RuntimeException('Module file path nesting too deep');
    }
    public static function checkedFiles(mixed $files):array {
        if(!is_array($files)||array_is_list($files)||count($files)<1||count($files)>self::MAX_FILES)throw new RuntimeException('Invalid package file inventory');
        $canonical=$files;ksort($canonical,SORT_STRING);
        if(array_keys($files)!==array_keys($canonical))throw new RuntimeException('File inventory must be sorted');
        $sum=0;
        foreach($files as $path=>$encoded){
            self::filePath($path);
            if(!is_string($encoded)||strlen($encoded)>ceil(self::MAX_FILE_BYTES*4/3)+4)throw new RuntimeException('Invalid package file payload');
            $decoded=base64_decode($encoded,true);
            if($decoded===false||base64_encode($decoded)!==$encoded)throw new RuntimeException('Invalid base64 package data');
            $sum+=strlen($decoded);
            if(strlen($decoded)>self::MAX_FILE_BYTES||$sum>self::MAX_TOTAL_BYTES)throw new RuntimeException('Module contents exceed limits');
        }
        return $files;
    }
    public static function manifest(array $files,string $name):array {
        if(!isset($files['module.json']))throw new RuntimeException('Missing module.json');
        $json=base64_decode($files['module.json'],true);
        $manifest=json_decode((string)$json,true,32,JSON_THROW_ON_ERROR);
        if(!is_array($manifest)||array_is_list($manifest)||($manifest['name']??null)!==$name||!is_string($manifest['version']??null)||!preg_match('/^\d+\.\d+\.\d+(?:-[a-z0-9.-]+)?$/Di',$manifest['version']))throw new RuntimeException('Invalid module manifest');
        if(($manifest['required']??false)!==false)throw new RuntimeException('Required platform modules cannot be distributed as packages');
        return $manifest;
    }
    private static function fileBytes(string $path):string {
        $size=filesize($path);if($size===false||$size>self::MAX_FILE_BYTES)throw new RuntimeException('File too large');
        $bytes=file_get_contents($path);if($bytes===false||strlen($bytes)!==$size)throw new RuntimeException('File read failed');return $bytes;
    }
}
