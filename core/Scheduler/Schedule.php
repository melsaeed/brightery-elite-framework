<?php
declare(strict_types=1);
namespace Brightery\Core\Scheduler;
use Brightery\Core\Database;
use Brightery\Core\Jobs\{Queue,Registry};
use RuntimeException;
/** UTC-only deterministic schedule declarations. Register jobs with a trusted module provider. */
final class Schedule {
    private array $definitions=[];
    public function __construct(private readonly Database $db,private readonly Queue $queue,private readonly Registry $registry){}
    public function everyMinutes(string $name,string $handler,int $interval,array $payload=[]):void {
        if($interval<1||$interval>1440)throw new RuntimeException('Invalid schedule interval');
        $this->register($name,$handler,'interval',$interval,$payload);
    }
    public function dailyUtc(string $name,string $handler,int $hour,int $minute=0,array $payload=[]):void {
        if($hour<0||$hour>23||$minute<0||$minute>59)throw new RuntimeException('Invalid daily UTC schedule');
        $this->register($name,$handler,'daily',$hour*60+$minute,$payload);
    }
    private function register(string $name,string $handler,string $type,int $value,array $payload):void {
        Registry::validateName($name);Registry::validateName($handler);
        if(isset($this->definitions[$name]))throw new RuntimeException('Duplicate schedule: '.$name);
        if(!$this->registry->has($handler))throw new RuntimeException('Schedule handler is not registered: '.$handler);
        $this->definitions[$name]=compact('name','handler','type','value','payload');
    }
    public function definitions():array {return $this->definitions;}
    /** Returns number of newly scheduled jobs. Use DB unique constraint for multi-server dedup. */
    public function tick(?\DateTimeImmutable $now=null):int {
        $now=($now??new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        $minute=intdiv($now->getTimestamp(),60);$minuteOfDay=(int)$now->format('G')*60+(int)$now->format('i');
        $created=0;
        foreach($this->definitions as $definition){
            $due=$definition['type']==='interval'?$minute%$definition['value']===0:$minuteOfDay===$definition['value'];
            if(!$due)continue;
            $slot=$now->format('Y-m-d H:i');
            $did=$this->db->transactional(function(Database $db)use($definition,$slot):bool {
                $affected=$db->execute('INSERT IGNORE INTO bf_schedule_runs(task_name,slot_utc) VALUES(?,?)',[$definition['name'],$slot]);
                if($affected===0)return false;
                // Scheduler executes in central context. Modules can dispatch tenant-specific work within a trusted handler.
                $id=$this->queue->dispatch($definition['handler'],$definition['payload']);
                $db->execute('UPDATE bf_schedule_runs SET job_id=? WHERE task_name=? AND slot_utc=?',[$id,$definition['name'],$slot]);
                return true;
            });
            if($did)$created++;
        }
        return $created;
    }
}
