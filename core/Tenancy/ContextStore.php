<?php
declare(strict_types=1);
namespace Brightery\Core\Tenancy;
use LogicException;
/** Request-local context; never cache it as a process-global static. */
final class ContextStore {
    private ?TenantContext $current=null;
    public function set(?TenantContext $context):void {$this->current=$context;}
    public function current():TenantContext {return $this->current??throw new LogicException('Tenant context has not been resolved');}
    public function clear():void {$this->current=null;}
}
