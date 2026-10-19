<?php
declare(strict_types=1);
namespace Brightery\Core\Tenancy;
use Brightery\Core\Cache\CacheInterface;

/** Enforces tenant-id key prefixing even with shared Redis/file cache backends. */
final class TenantCache {
    public function __construct(private readonly CacheInterface $cache,private readonly ContextStore $contexts){}
    private function key(string $key):string {
        $id=$this->contexts->current()->requireTenantId();
        return 'tenant:'.$id.':'.$key;
    }
    public function get(string $key,mixed $default=null):mixed {return $this->cache->get($this->key($key),$default);}
    public function put(string $key,mixed $value,int $ttl=300):void {$this->cache->put($this->key($key),$value,$ttl);}
    public function forget(string $key):void {$this->cache->forget($this->key($key));}
    public function remember(string $key,int $ttl,callable $callback):mixed {return $this->cache->remember($this->key($key),$ttl,$callback);}
}
