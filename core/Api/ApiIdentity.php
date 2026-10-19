<?php
declare(strict_types=1);
namespace Brightery\Core\Api;
use Brightery\Core\Http\Request;
use Brightery\Core\Tenancy\{TenantContext,TenantPolicy};
/** Request scoped; no session or global Auth privilege inheritance. */
final class ApiIdentity {
    private ?array $identity=null;
    public function __construct(private readonly TokenService $tokens){}
    public function authenticate(Request $request,TenantContext $context):bool {
        $this->identity=null;
        $authorization=$request->header('Authorization');
        if(!preg_match('/^Bearer ([^ ]{10,96})$/D',$authorization,$m))return false;
        $this->identity=$this->tokens->identify($m[1],$context);
        return $this->identity!==null;
    }
    public function user():?array {return $this->identity;}
    public function allows(string $scope,?string $tenantPermission=null):bool {
        if($this->identity===null||!in_array($scope,$this->identity['scopes'],true))return false;
        return $tenantPermission===null || (isset($this->identity['role']) && TenantPolicy::allows((string)$this->identity['role'],$tenantPermission));
    }
}
