<?php
declare(strict_types=1);
namespace Brightery\Core\Tenancy;
use LogicException;
final class TenantContext {
    public function __construct(public readonly string $mode,public readonly ?int $tenantId=null,public readonly ?string $slug=null,public readonly ?string $hostname=null){
        if(!in_array($mode,['single','central','tenant'],true))throw new LogicException('Invalid tenancy mode');
        if($mode==='tenant'&&($tenantId===null||$tenantId<1))throw new LogicException('Tenant context requires a valid ID');
        if($mode!=='tenant'&&$tenantId!==null)throw new LogicException('Non-tenant context cannot carry a tenant ID');
    }
    public function isTenant():bool {return $this->mode==='tenant';}
    public function requireTenantId():int {return $this->tenantId??throw new LogicException('Tenant-scoped data requires an explicit tenant context');}
}
