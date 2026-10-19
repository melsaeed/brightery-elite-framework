<?php
declare(strict_types=1);
namespace Brightery\Core\Operations;
use Brightery\Core\Database;
use Brightery\Core\Jobs\Queue;
use Brightery\Core\Kernel;
use RuntimeException;

/** Read-only control-plane diagnostics. Avoid public endpoints that disclose infrastructure. */
final class HealthReport {
    public function __construct(private readonly Database $db,private readonly Queue $queue,private readonly WorkerRegistry $workers){}
    public static function classify(array $counts,?int $oldestPendingSeconds,int $healthyWorkers):array {
        $pending=(int)($counts['pending']??0);$failed=(int)($counts['failed']??0);
        $status=($pending>0&&$healthyWorkers===0)||($oldestPendingSeconds!==null&&$oldestPendingSeconds>900)?'critical':($failed>0?'warning':'healthy');
        return ['status'=>$status,'pending'=>$pending,'failed'=>$failed,'oldest_pending_seconds'=>$oldestPendingSeconds,'active_workers'=>$healthyWorkers];
    }
    public function snapshot():array {
        $counts=$this->queue->stats();
        $oldest=$this->db->row("SELECT TIMESTAMPDIFF(SECOND,MIN(available_at),UTC_TIMESTAMP(6)) AS age FROM bf_jobs WHERE status='pending' AND available_at <= UTC_TIMESTAMP(6)");
        $age=$oldest===null||$oldest['age']===null?null:max(0,(int)$oldest['age']);
        $workers=$this->workers->recent();$healthy=0;
        foreach($workers as $worker)if($worker['healthy'])$healthy++;
        return ['framework'=>Kernel::VERSION,'queue'=>$counts,'assessment'=>self::classify($counts,$age,$healthy),'workers'=>$workers,'generated_at'=>gmdate('c')];
    }
}
