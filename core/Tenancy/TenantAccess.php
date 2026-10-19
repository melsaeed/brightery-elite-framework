<?php
declare(strict_types=1);
namespace Brightery\Core\Tenancy;
use Brightery\Core\Database;
use Brightery\Core\Security;
use Brightery\Core\Http\{Request,Response};

/** Every authorization check re-queries membership to honor revocations immediately. */
final class TenantAccess {
    public function __construct(private readonly Database $database,private readonly ContextStore $contexts,private readonly Security $security){}
    public function tenantId():int {return $this->contexts->current()->requireTenantId();}
    public function membership():?array {
        $tenantId=$this->tenantId();
        $userId=$this->security->userId();
        if($userId===null)return null;
        return $this->database->row("SELECT u.id,u.name,u.email,m.role FROM tenant_memberships m JOIN users u ON u.id=m.user_id JOIN tenants t ON t.id=m.tenant_id WHERE m.tenant_id=? AND m.user_id=? AND u.is_active=1 AND t.status='active' LIMIT 1",[$tenantId,$userId]);
    }
    public function allows(string $permission):bool {
        $membership=$this->membership();
        return $membership!==null && TenantPolicy::allows((string)$membership['role'],$permission);
    }
    public function guard(string $permission):callable {
        return function(Request $request,callable $next)use($permission):Response {
            if(!$this->contexts->current()->isTenant())return Response::html('Tenant workspace unavailable',404);
            if($this->security->userId()===null)return Response::redirect('/workspace/login');
            if(!$this->allows($permission))return Response::html('Forbidden',403);
            return $next();
        };
    }
    public function attempt(string $email,string $password):bool {
        $tenantId=$this->tenantId();
        $email=strtolower(trim($email));
        $row=$this->database->row("SELECT u.id,u.password_hash FROM users u JOIN tenant_memberships m ON m.user_id=u.id JOIN tenants t ON t.id=m.tenant_id WHERE u.email=? AND u.is_active=1 AND m.tenant_id=? AND t.status='active' LIMIT 1",[$email,$tenantId]);
        if(!$row||!password_verify($password,(string)$row['password_hash']))return false;
        if(password_needs_rehash((string)$row['password_hash'],PASSWORD_ARGON2ID)){
            $this->database->execute('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_ARGON2ID),(int)$row['id']]);
        }
        if (\Brightery\Core\App::get()->service(\Brightery\Core\Mfa\Totp::class)->enabled((int)$row['id'])) $this->security->beginMfa((int)$row['id'],'/workspace');
        else $this->security->login((int)$row['id']);
        return true;
    }
}
