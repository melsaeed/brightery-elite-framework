<?php
declare(strict_types=1);
namespace Brightery\Core\Cache;
final class FileCache implements CacheInterface {
    public function __construct(private readonly string $directory) {if (!is_dir($directory)) mkdir($directory,0700,true);}
    private function path(string $key): string {return rtrim($this->directory,'/').'/'.hash('sha256',$key).'.cache';}
    public function get(string $key,mixed $default=null): mixed {
        $file=$this->path($key);if (!is_file($file)) return $default;
        $json=@file_get_contents($file);$entry=is_string($json)?json_decode($json,true):null;
        if (!is_array($entry) || ($entry['expiry']??0)<time()) { @unlink($file);return $default; }
        return $entry['value'] ?? $default;
    }
    public function put(string $key,mixed $value,int $ttl=300): void {
        if($ttl<=0) { $this->forget($key);return; }
        $payload=json_encode(['expiry'=>time()+$ttl,'value'=>$value],JSON_THROW_ON_ERROR);
        $file=$this->path($key);$tmp=tempnam($this->directory,'tmp');
        if($tmp===false) throw new \RuntimeException('Cache temp failed');
        file_put_contents($tmp,$payload,LOCK_EX);chmod($tmp,0600);rename($tmp,$file);
    }
    public function remember(string $key,int $ttl,callable $callback): mixed {
        $sentinel=new \stdClass; $existing=$this->get($key,$sentinel);
        if ($existing!==$sentinel) return $existing;
        // Lock to avoid parallel cache stampedes.
        $lock=fopen($this->path($key).'.lock','c');if($lock===false) throw new \RuntimeException('Cache lock failed');
        try{flock($lock,LOCK_EX);$existing=$this->get($key,$sentinel);if ($existing!==$sentinel)return $existing;
          $value=$callback();$this->put($key,$value,$ttl);return $value;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function forget(string $key): void {@unlink($this->path($key));}
    public function flush(): void {foreach(glob($this->directory.'/*.cache')?:[] as $file) @unlink($file);}
}
