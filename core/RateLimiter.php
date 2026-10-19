<?php
declare(strict_types=1);
namespace Brightery\Core;
use Brightery\Core\Cache\CacheInterface;
/** Basic fixed-window limiter for one node. Redis atomic INCR is needed at scale. */
final class RateLimiter {
    public function __construct(private readonly CacheInterface $cache){}
    public function hit(string $key,int $limit=5,int $window=300): bool {
        $bucket='ratelimit:'.$key.':'.(int)floor(time()/$window);
        // File cache atomicity is not guaranteed for increments; use an exclusive lock.
        $lock=base_path('storage/private/ratelimit-'.hash('sha256',$bucket).'.lock');
        $handle=fopen($lock,'c');if($handle===false)return false;
        try{flock($handle,LOCK_EX);$count=(int)$this->cache->get($bucket,0);$count++;$this->cache->put($bucket,$count,$window+30);return $count<=$limit;}
        finally{flock($handle,LOCK_UN);fclose($handle);}
    }
}
