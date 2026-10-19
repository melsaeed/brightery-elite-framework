<?php
declare(strict_types=1);
namespace Brightery\Core\Tenancy;
use Brightery\Core\{Database,Security,Auth};
use Brightery\Core\Audit\AuditTrail;
use RuntimeException;

/** Serialize membership changes on tenant row; never mutate memberships outside tenant_id. */
final class TenantMemberships {
    public function __construct(private readonly Database $database,private readonly ContextStore $contexts,private readonly Security $security,private readonly TenantAccess $access,private readonly Auth $auth,private readonly AuditTrail $audit){}
    public function list(int $tenantId):array {
        $context=$this->contexts->current();
        $allowed=($context->isTenant()&&$context->requireTenantId()===$tenantId&&$this->access->allows('members.view'))
            ||($context->mode==='central'&&$this->auth->can('tenants.manage'));
        if(!$allowed)throw new RuntimeException('Tenant membership list access denied');
        return $this->database->rows('SELECT m.user_id,u.name,u.email,m.role FROM tenant_memberships m JOIN users u ON u.id=m.user_id WHERE m.tenant_id=? ORDER BY FIELD(m.role,\'owner\',\'admin\',\'editor\',\'viewer\'),u.name',[$tenantId]);
    }
    public function setRole(int $tenantId,int $actorId,int $targetId,string $role):void {
        $this->requireActor($tenantId,$actorId);
        if(!TenantPolicy::validRole($role))throw new RuntimeException('Unsupported membership role');
        $this->database->transactional(function(Database $db)use($tenantId,$actorId,$targetId,$role):void {
            $this->lockTenant($db,$tenantId);
            $actor=$this->role($db,$tenantId,$actorId);
            $current=$this->role($db,$tenantId,$targetId);
            if($actor===null||$current===null||!TenantPolicy::mayChange($actor,$current,$role))throw new RuntimeException('Insufficient membership permissions');
            if($current==='owner'&&$role!=='owner')$this->ensureAnotherOwner($db,$tenantId,$targetId);
            $db->execute('UPDATE tenant_memberships SET role=? WHERE tenant_id=? AND user_id=?',[$role,$tenantId,$targetId]);
            $this->audit->record('tenant.members.role_changed',$actorId,'user',(string)$targetId,['from'=>$current,'to'=>$role]);
        });
    }
    public function remove(int $tenantId,int $actorId,int $targetId):void {
        $this->requireActor($tenantId,$actorId);
        $this->database->transactional(function(Database $db)use($tenantId,$actorId,$targetId):void {
            $this->lockTenant($db,$tenantId);
            $actor=$this->role($db,$tenantId,$actorId);
            $current=$this->role($db,$tenantId,$targetId);
            if($actor===null||$current===null||!TenantPolicy::mayChange($actor,$current,'viewer'))throw new RuntimeException('Insufficient membership permissions');
            if($current==='owner')$this->ensureAnotherOwner($db,$tenantId,$targetId);
            $db->execute('DELETE FROM tenant_memberships WHERE tenant_id=? AND user_id=?',[$tenantId,$targetId]);
            $this->audit->record('tenant.members.removed',$actorId,'user',(string)$targetId,['role'=>$current]);
        });
    }
    private function requireActor(int $tenantId,int $actorId):void {
        if($this->contexts->current()->requireTenantId()!==$tenantId || $this->security->userId()!==$actorId)
            throw new RuntimeException('Membership actor or tenant mismatch');
    }
    private function lockTenant(Database $db,int $id):void {
        if(!$db->row("SELECT id FROM tenants WHERE id=? AND status='active' FOR UPDATE",[$id]))throw new RuntimeException('Tenant unavailable');
    }
    private function role(Database $db,int $tenantId,int $userId):?string {
        $r=$db->row('SELECT m.role FROM tenant_memberships m JOIN users u ON u.id=m.user_id WHERE m.tenant_id=? AND m.user_id=? AND u.is_active=1',[$tenantId,$userId]);
        return $r?(string)$r['role']:null;
    }
    private function ensureAnotherOwner(Database $db,int $tenantId,int $userId):void {
        $r=$db->row("SELECT COUNT(*) AS n FROM tenant_memberships WHERE tenant_id=? AND role='owner' AND user_id<>?",[$tenantId,$userId]);
        if((int)($r['n']??0)===0)throw new RuntimeException('Cannot remove the last tenant owner');
    }
}
