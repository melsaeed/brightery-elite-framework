<?php
declare(strict_types=1);
namespace Brightery\Core\Crypto;
use RuntimeException;
final class Encrypter {
    private string $key;
    public function __construct(){
        $hex=(string)env('APP_KEY','');
        if(!preg_match('/^[a-f0-9]{64}$/i',$hex)) throw new RuntimeException('APP_KEY must be a generated 64-character hexadecimal key');
        if(!extension_loaded('sodium'))throw new RuntimeException('Sodium PHP extension required');
        $this->key=sodium_hex2bin($hex);
    }
    public function encrypt(string $plaintext):string {
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher=sodium_crypto_secretbox($plaintext,$nonce,$this->key);
        return rtrim(strtr(base64_encode("v1".$nonce.$cipher),'+/','-_'),'=');
    }
    public function decrypt(string $token):string {
        $bytes=base64_decode(strtr($token,'-_','+/'),true);
        if(!is_string($bytes)||!str_starts_with($bytes,'v1')||strlen($bytes)<2+SODIUM_CRYPTO_SECRETBOX_NONCEBYTES+SODIUM_CRYPTO_SECRETBOX_MACBYTES)throw new RuntimeException('Invalid encrypted payload');
        $nonce=substr($bytes,2,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher=substr($bytes,2+SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain=sodium_crypto_secretbox_open($cipher,$nonce,$this->key);
        if($plain===false)throw new RuntimeException('Authentication failed');
        return $plain;
    }
}
