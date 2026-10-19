<?php
declare(strict_types=1);
namespace Brightery\Core;
/** Cross-worker atomic DB fixed windows. Bound identity MUST be trusted server-side. */
final class RateBuckets {
    public function __construct(private readonly Database $db){}
    public static function key(?int $tenantId,string $category,string $identity):string {
        if(strlen($category)>80 || !preg_match('/^[a-z][a-z0-9_.:-]*$/D',$category))throw new \InvalidArgumentException('Invalid rate-limit category');
        return hash('sha256',($tenantId===null?'central':(string)$tenantId).'|'.$category.'|'.$identity);
    }
    public function hit(?int $tenantId,string $category,string $identity,int $limit=120,int $window=60):bool {
        if($limit<1||$limit>100000||$window<1||$window>86400)throw new \InvalidArgumentException('Invalid limiter bounds');
        $key=self::key($tenantId,$category,$identity);$slot=intdiv(time(),$window)*$window;
        // MySQL UPSERT increments are serialized by PK locks across nodes.
        $this->db->execute('INSERT INTO bf_rate_buckets(digest,bucket_start,hits,expires_at) VALUES(?,?,1,FROM_UNIXTIME(?)) ON DUPLICATE KEY UPDATE hits=hits+1',[$key,$slot,$slot+$window+90]);
        $row=$this->db->row('SELECT hits FROM bf_rate_buckets WHERE digest=? AND bucket_start=?',[$key,$slot]);
        return $row!==null && (int)$row['hits']<=$limit;
    }
    public function prune():int{return $this->db->execute('DELETE FROM bf_rate_buckets WHERE expires_at<UTC_TIMESTAMP()');}
}
