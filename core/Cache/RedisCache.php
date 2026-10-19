<?php
declare(strict_types=1);
namespace Brightery\Core\Cache;
final class RedisCache implements CacheInterface {
    public function __construct(private readonly \Redis $redis,private readonly string $prefix='bf:'){}
    public function get(string $key,mixed $default=null): mixed {$v=$this->redis->get($this->prefix.hash('sha256',$key));return $v===false?$default:json_decode($v,true,512,JSON_THROW_ON_ERROR);}
    public function put(string $key,mixed $value,int $ttl=300): void {if($ttl>0)$this->redis->setex($this->prefix.hash('sha256',$key),$ttl,json_encode($value,JSON_THROW_ON_ERROR));}
    public function forget(string $key): void {$this->redis->del($this->prefix.hash('sha256',$key));}
    public function remember(string $key,int $ttl,callable $callback): mixed {$sentinel=new \stdClass;$v=$this->get($key,$sentinel);if ($v!==$sentinel)return $v;$v=$callback();$this->put($key,$v,$ttl);return $v;}
}
