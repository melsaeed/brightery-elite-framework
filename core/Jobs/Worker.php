<?php
declare(strict_types=1);
namespace Brightery\Core\Jobs;
use Brightery\Core\Database;
use Brightery\Core\Container;
use Brightery\Core\Tenancy\{ContextStore,TenantContext};
use RuntimeException;
/** One bounded task at a time; always clear tenant and scoped DI objects after execution. */
final class Worker {
    public function __construct(private readonly Queue $queue,private readonly Registry $registry,private readonly ContextStore $contexts,private readonly Database $db,private readonly Container $container){}
    public function one():bool {
        if(PHP_SAPI!=='cli')throw new RuntimeException('Queue workers may only run from CLI');
        $job=$this->queue->reserve();
        if($job===null)return false;
        $this->container->resetScope();$this->contexts->clear();
        try {
            if($job['tenant_id']!==null){
                $tenant=$this->db->row("SELECT id,slug FROM tenants WHERE id=? AND status='active'",[(int)$job['tenant_id']]);
                if(!$tenant)throw new RuntimeException('Tenant unavailable or suspended');
                $this->contexts->set(new TenantContext('tenant',(int)$tenant['id'],(string)$tenant['slug']));
            }else{$this->contexts->set(new TenantContext('central'));}
            $payload=json_decode((string)$job['payload'],true,32,JSON_THROW_ON_ERROR);
            if(!is_array($payload))throw new RuntimeException('Invalid JSON job payload');
            $this->registry->run((string)$job['handler'],$payload);
            if(!$this->queue->complete($job))throw new RuntimeException('Job lease expired; unable to mark complete');
        }catch(\Throwable $error){
            $this->queue->fail($job,$error);
            // Caller can inspect failed/pending rows. Do not leak payloads or credentials into logs.
            try {
                $this->container->get(\Brightery\Core\Observability\LoggerInterface::class)->write('error','queue.failed',[
                    'job_id'=>(int)$job['id'],'handler'=>(string)$job['handler'],'exception_class'=>get_class($error)]);
            } catch(\Throwable) {/* Failure to log must not leak queue payloads or secrets. */}
        }finally{$this->contexts->clear();$this->container->resetScope();}
        return true;
    }
}
