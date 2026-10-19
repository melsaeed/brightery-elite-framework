<?php
declare(strict_types=1);
namespace Brightery\Core\Modules;
use RuntimeException;

/** Private, explicitly operator-managed trust store. */
final class TrustedKeys {
    public function __construct(private readonly string $path){}
    public function all():array {
        if(!is_file($this->path))return [];
        if(is_link($this->path))throw new RuntimeException('Trust store cannot be symlinked');
        $keys=json_decode((string)file_get_contents($this->path),true,16,JSON_THROW_ON_ERROR);
        if(!is_array($keys)||($keys!==[]&&array_is_list($keys)))throw new RuntimeException('Invalid trusted signing keys');
        foreach($keys as $id=>$public){
            ModulePackage::keyId($id);
            $bytes=is_string($public)?base64_decode($public,true):false;
            if($bytes===false||strlen($bytes)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)throw new RuntimeException('Invalid stored signing key');
        }
        return $keys;
    }
    private function save(array $keys):void {
        ksort($keys);
        $directory=dirname($this->path);
        if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Cannot create trust directory');
        $temp=tempnam($directory,'trust-');if($temp===false)throw new RuntimeException('Trust store unavailable');
        try{
            if(file_put_contents($temp,json_encode((object)$keys,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new RuntimeException('Cannot save trust store');
            chmod($temp,0600);if(!rename($temp,$this->path))throw new RuntimeException('Cannot publish trust store');
        }finally{if(is_file($temp))unlink($temp);}
    }
    public function revoke(string $keyId):void {
        ModulePackage::keyId($keyId);
        $keys=$this->all();
        if(!isset($keys[$keyId]))throw new RuntimeException('Signing key is not trusted');
        unset($keys[$keyId]);
        $this->save($keys);
    }
    public function trust(string $keyId,string $base64PublicKey):void {
        ModulePackage::keyId($keyId);
        $decoded=base64_decode($base64PublicKey,true);
        if($decoded===false||strlen($decoded)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES||base64_encode($decoded)!==$base64PublicKey)throw new RuntimeException('Expected base64 Ed25519 public key');
        $keys=$this->all();
        if(isset($keys[$keyId])&&!hash_equals($keys[$keyId],$base64PublicKey))throw new RuntimeException('Signing key ID is already trusted with a different value');
        $keys[$keyId]=$base64PublicKey;
        $this->save($keys);
    }
}
