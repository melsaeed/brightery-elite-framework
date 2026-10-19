<?php
declare(strict_types=1);
namespace Brightery\Core\Operations;
use Brightery\Core\Database;
use RuntimeException;

/** An operator-oriented worker heartbeat (not a substitute for renewing job leases). */
final class WorkerRegistry {
    public function __construct(private readonly Database $db){}
    public static function workerId():string { return bin2hex(random_bytes(16)); }
    public static function validateId(string $id):void {
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new RuntimeException('Invalid worker identifier');
    }
    public function start(string $id):void {
        self::validateId($id);
        $this->db->execute("INSERT INTO bf_worker_heartbeats(worker_id,started_at,last_seen_at,state,jobs_processed) VALUES(?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),'idle',0)",[$id]);
    }
    public function beat(string $id,string $state='idle',bool $completed=false):void {
        self::validateId($id);
        if(!in_array($state,['idle','working','stopped'],true))throw new RuntimeException('Invalid worker state');
        $affected=$this->db->execute('UPDATE bf_worker_heartbeats SET last_seen_at=UTC_TIMESTAMP(6),state=?,jobs_processed=jobs_processed+? WHERE worker_id=?',[$state,(int)$completed,$id]);
        if($affected!==1 && $this->db->row('SELECT worker_id FROM bf_worker_heartbeats WHERE worker_id=?',[$id])===null)throw new RuntimeException('Worker heartbeat registration missing');
    }
    public function stop(string $id):void { $this->beat($id,'stopped'); }
    /** Returns limited status for central operators; no hostnames, PIDs or secrets. */
    public function recent(int $limit=25,int $staleSeconds=180):array {
        if($limit<1||$limit>100||$staleSeconds<10||$staleSeconds>86400)throw new RuntimeException('Invalid worker status query');
        $rows=$this->db->rows('SELECT worker_id,state,jobs_processed,started_at,last_seen_at,TIMESTAMPDIFF(SECOND,last_seen_at,UTC_TIMESTAMP(6)) AS age_seconds FROM bf_worker_heartbeats ORDER BY last_seen_at DESC LIMIT '.(int)$limit);
        foreach($rows as &$row){$row['age_seconds']=max(0,(int)$row['age_seconds']);$row['healthy']=$row['state']!=='stopped'&&$row['age_seconds']<=$staleSeconds;}
        return $rows;
    }
    public function prune(int $days=14):int {
        if($days<1||$days>365)throw new RuntimeException('Invalid worker retention');
        return $this->db->execute('DELETE FROM bf_worker_heartbeats WHERE last_seen_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? DAY)',[$days]);
    }
}
