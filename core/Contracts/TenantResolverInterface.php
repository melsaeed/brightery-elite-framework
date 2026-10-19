<?php
declare(strict_types=1);
namespace Brightery\Core\Contracts;
use Brightery\Core\Http\Request;
use Brightery\Core\Tenancy\TenantContext;

/** Implement in app/ or modules/ to swap resolution logic without patching the core. */
interface TenantResolverInterface {
    public function resolve(Request $request):?TenantContext;
}
