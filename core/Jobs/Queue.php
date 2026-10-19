<?php
declare(strict_types=1);
namespace Brightery\Core\Jobs;
use Brightery\Core\Database;
use Brightery\Core\Tenancy\ContextStore;
use RuntimeException;
/** DB-backed outbox with atomic claims and bounded retries. UTC timestamps, no serialized PHP code. */
final class Queue {
    public function __construct(private readonly Database $db,private readonly ContextStore $contexts,private readonly Registry $handlers){}
    public function dispatch(string $handler,array $payload=[],int $delaySeconds=0,int $maxAttempts=3):int {
        Registry::validateName($handler);
        if(!$this->handlers->has($handler))throw new RuntimeException('Unregistered job: '.$handler);
        if($delaySeconds<0||$delaySeconds>2592000||$maxAttempts<1||$maxAttempts>25)throw new RuntimeException('Invalid job retry/delay');
        $serialized=json_encode($payload,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE,32);
        if(strlen($serialized)>65536)throw new RuntimeException('Job exceeds 64 KiB payload limit');
        $tenantId=$this->contexts->current()->isTenant()?$this->contexts->current()->requireTenantId():null;
        $this->db->execute('INSERT INTO bf_jobs(tenant_id,handler,payload,max_attempts,available_at) VALUES(?,?,?,?,DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND))',[$tenantId,$handler,$serialized,$maxAttempts,$delaySeconds]);
        return (int)$this->db->pdo()->lastInsertId();
    }
    /** Atomic claim: MySQL updates a single oldest eligible row, concurrency protected by row locks. */
    public function reserve(int $leaseSeconds=120):?array {
        if($leaseSeconds<10||$leaseSeconds>3600)throw new RuntimeException('Lease must be 10-3600 seconds');
        // Abandoned leases on their last attempt must become terminal rather than remaining stuck.
        $this->db->execute("UPDATE bf_jobs SET status='failed',lease_token=NULL,leased_until=NULL,last_error='Lease expired on final attempt' WHERE status='reserved' AND leased_until < UTC_TIMESTAMP(6) AND attempts >= max_attempts");
        $lease=bin2hex(random_bytes(32));
        $affected=$this->db->execute("UPDATE bf_jobs SET status='reserved',attempts=attempts+1,lease_token=?,leased_until=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND) WHERE (status='pending' OR (status='reserved' AND leased_until < UTC_TIMESTAMP(6))) AND available_at <= UTC_TIMESTAMP(6) AND attempts < max_attempts ORDER BY available_at,id LIMIT 1",[$lease,$leaseSeconds]);
        if($affected===0)return null;
        $job=$this->db->row('SELECT id,tenant_id,handler,payload,attempts,max_attempts,lease_token FROM bf_jobs WHERE lease_token=?',[$lease]);
        if(!$job)throw new RuntimeException('Job lease was lost');
        return $job;
    }
    public function complete(array $job):bool {
        return $this->db->execute("UPDATE bf_jobs SET status='completed',lease_token=NULL,leased_until=NULL,last_error=NULL WHERE id=? AND lease_token=? AND status='reserved'",[$job['id'],$job['lease_token']])===1;
    }
    public function fail(array $job,\Throwable $error):bool {
        $attempt=(int)$job['attempts'];$terminal=$attempt>=(int)$job['max_attempts'];
        $delay=min(3600,2**min($attempt,11));
        $safeError=substr(get_class($error),0,150); // Never persist arbitrary exception messages; they may contain secrets.
        return $this->db->execute('UPDATE bf_jobs SET status=?,available_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL ? SECOND),last_error=?,lease_token=NULL,leased_until=NULL WHERE id=? AND lease_token=? AND status=\'reserved\'',[$terminal?'failed':'pending',$delay,$safeError,$job['id'],$job['lease_token']])===1;
    }
    public function heartbeat(array $job,int $leaseSeconds=120):bool {
        if($leaseSeconds<10||$leaseSeconds>3600)throw new RuntimeException('Invalid heartbeat');
        return $this->db->execute("UPDATE bf_jobs SET leased_until=DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND) WHERE id=? AND lease_token=? AND status='reserved'",[$leaseSeconds,$job['id'],$job['lease_token']])===1;
    }
    public function stats():array {
        $rows=$this->db->rows('SELECT status,COUNT(*) total FROM bf_jobs GROUP BY status');
        $counts=['pending'=>0,'reserved'=>0,'completed'=>0,'failed'=>0];
        foreach($rows as $row)$counts[$row['status']]=(int)$row['total'];
        return $counts;
    }
}
