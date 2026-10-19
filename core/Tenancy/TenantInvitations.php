<?php
declare(strict_types=1);
namespace Brightery\Core\Tenancy;
use Brightery\Core\{Database,Security,Auth,App};
use Brightery\Core\Audit\AuditTrail;
use Brightery\Core\Mail\{Outbox,PasswordReset,TemplateCatalog};
use RuntimeException;

/** Opaque 256-bit one-time invitations; the database stores only SHA-256 digests. */
final class TenantInvitations {
    public function __construct(
        private readonly Database $database,
        private readonly ContextStore $contexts,
        private readonly Security $security,
        private readonly Auth $auth,
        private readonly TenantAccess $access,
        private readonly AuditTrail $audit,
    ){}
    public static function validToken(string $token):bool {return (bool)preg_match('/^[a-f0-9]{64}$/D',$token);}
    public function issue(int $tenantId,string $email,string $role,int $creatorId,?string $origin=null):string {
        $email=strtolower(trim($email));
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190||!TenantPolicy::validRole($role))throw new RuntimeException('Invalid invitation recipient or role');
        $context=$this->contexts->current();
        if($this->security->userId()!==$creatorId)throw new RuntimeException('Invitation issuer is not the authenticated user');
        if($context->isTenant()){
            $actor=$this->access->membership();
            if($context->requireTenantId()!==$tenantId || !$actor || !TenantPolicy::mayGrant((string)$actor['role'],$role))throw new RuntimeException('Insufficient invitation permissions');
        }elseif($context->mode!=='central'||!$this->auth->can('tenants.manage'))throw new RuntimeException('Central tenant permission required');
        $tenant=$this->database->row("SELECT id FROM tenants WHERE id=? AND status='active'",[$tenantId]);
        if(!$tenant)throw new RuntimeException('Tenant is not active');
        if($origin!==null){
            if(!PasswordReset::validOrigin($origin))throw new RuntimeException('Invalid invitation URL');
            $domain=(string)parse_url($origin,PHP_URL_HOST);
            if(!$this->database->row('SELECT 1 FROM tenant_domains WHERE tenant_id=? AND hostname=? LIMIT 1',[$tenantId,$domain]))throw new RuntimeException('Invitation origin does not belong to tenant');
        }
        $token=bin2hex(random_bytes(32));
        $this->database->transactional(function(Database $db)use($tenantId,$email,$role,$token,$creatorId,$origin):void {
            $db->execute('INSERT INTO tenant_invitations(tenant_id,email,role,token_hash,created_by,expires_at) VALUES(?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 48 HOUR))',[$tenantId,$email,$role,hash('sha256',$token),$creatorId]);
            if($origin!==null){
                $message=App::get()->service(TemplateCatalog::class)->render('tenant.invite',['role'=>$role,'url'=>$origin.'/invite/'.$token]);
                App::get()->service(Outbox::class)->enqueue($email,$message['subject'],$message['body'],$creatorId);
            }
            $this->audit->record('tenant.invitation.issued',$creatorId,'tenant',(string)$tenantId,['role'=>$role]);
        });
        return $token;
    }
    public function inspect(int $tenantId,string $token):?array {
        if(!self::validToken($token))return null;
        if($this->contexts->current()->requireTenantId()!==$tenantId)return null;
        return $this->database->row("SELECT i.email,i.role,i.expires_at,t.display_name FROM tenant_invitations i JOIN tenants t ON t.id=i.tenant_id WHERE i.tenant_id=? AND i.token_hash=? AND i.consumed_at IS NULL AND i.expires_at>UTC_TIMESTAMP() AND t.status='active' LIMIT 1",[$tenantId,hash('sha256',$token)]);
    }
    public function accept(int $tenantId,string $token,string $name,string $password):int {
        if(!self::validToken($token)||strlen($password)<12||strlen($password)>256)throw new RuntimeException('Invitation invalid, expired, or password too short (12+ characters)');
        if($this->contexts->current()->requireTenantId()!==$tenantId)throw new RuntimeException('Invitation belongs to another tenant');
        return $this->database->transactional(function(Database $db)use($tenantId,$token,$name,$password):int {
            $row=$db->row("SELECT i.id,i.email,i.role FROM tenant_invitations i JOIN tenants t ON t.id=i.tenant_id WHERE i.tenant_id=? AND i.token_hash=? AND i.consumed_at IS NULL AND i.expires_at>UTC_TIMESTAMP() AND t.status='active' LIMIT 1 FOR UPDATE",[$tenantId,hash('sha256',$token)]);
            if(!$row||!TenantPolicy::validRole((string)$row['role']))throw new RuntimeException('Invitation invalid or expired');
            $user=$db->row('SELECT id,password_hash,is_active FROM users WHERE email=? LIMIT 1',[$row['email']]);
            if($user){
                if(!(bool)$user['is_active']||!password_verify($password,(string)$user['password_hash']))throw new RuntimeException('Invalid account credentials');
                $userId=(int)$user['id'];
            }else{
                $name=trim($name);
                if(strlen($name)<2||strlen($name)>160)throw new RuntimeException('Enter a name between 2 and 160 characters');
                $db->execute('INSERT INTO users(name,email,password_hash) VALUES(?,?,?)',[$name,$row['email'],password_hash($password,PASSWORD_ARGON2ID)]);
                $userId=(int)$db->pdo()->lastInsertId();
            }
            if($db->row('SELECT 1 FROM tenant_memberships WHERE tenant_id=? AND user_id=?',[$tenantId,$userId]))throw new RuntimeException('Account already belongs to this tenant');
            $db->execute('INSERT INTO tenant_memberships(tenant_id,user_id,role) VALUES(?,?,?)',[$tenantId,$userId,$row['role']]);
            $db->execute('UPDATE tenant_invitations SET consumed_at=UTC_TIMESTAMP() WHERE id=? AND consumed_at IS NULL',[$row['id']]);
            $this->audit->record('tenant.invitation.accepted',$userId,'tenant',(string)$tenantId,['role'=>$row['role']]);
            return $userId;
        });
    }
    public function prune():int {return $this->database->execute('DELETE FROM tenant_invitations WHERE consumed_at IS NOT NULL OR expires_at<UTC_TIMESTAMP()');}
}
