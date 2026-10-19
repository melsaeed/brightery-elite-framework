<?php
declare(strict_types=1);
namespace Brightery\Core\Api;
use Brightery\Core\Database;
use Brightery\Core\Tenancy\TenantContext;
use RuntimeException;
/** Tokens printed only at creation. Digests stored; tenant/member verified on every authenticated call. */
final class TokenService {
    public const SCOPES=['profile:read','workspace:read','modules:read','modules:write'];
    public function __construct(private readonly Database $db){}
    public static function validScopes(array $scopes):bool {
        if($scopes===[]||count($scopes)>20)return false;
        return count(array_unique($scopes))===count($scopes) && count(array_diff($scopes,self::SCOPES))===0;
    }
    public function issue(int $userId,?int $tenantId,string $name,array $scopes,int $days=30):string {
        if($userId<1||($tenantId!==null&&$tenantId<1)||!self::validScopes($scopes)||$days<1||$days>365||strlen($name)<2||strlen($name)>120)throw new RuntimeException('Invalid token input');
        $row=$this->db->row('SELECT id FROM users WHERE id=? AND is_active=1',[$userId]);
        if(!$row)throw new RuntimeException('Active user required');
        if($tenantId!==null){
            $member=$this->db->row("SELECT 1 FROM tenant_memberships m JOIN tenants t ON t.id=m.tenant_id WHERE m.tenant_id=? AND m.user_id=? AND t.status='active' LIMIT 1",[$tenantId,$userId]);
            if(!$member)throw new RuntimeException('Active tenant membership required');
        }
        $secret=bin2hex(random_bytes(32));
        $hash=hash('sha256',$secret);
        $this->db->execute('INSERT INTO bf_api_tokens(user_id,tenant_id,name,token_hash,scopes,expires_at) VALUES(?,?,?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? DAY))',[$userId,$tenantId,$name,$hash,json_encode(array_values($scopes),JSON_THROW_ON_ERROR),$days]);
        return 'bfpat_'.(int)$this->db->pdo()->lastInsertId().'_'.$secret;
    }
    public static function parse(string $token):?array {
        if(strlen($token)>96||!preg_match('/^bfpat_([1-9][0-9]{0,18})_([a-f0-9]{64})$/D',$token,$m))return null;
        if((int)$m[1]<1 || (string)(int)$m[1]!==$m[1])return null;
        return ['id'=>(int)$m[1],'digest'=>hash('sha256',$m[2])];
    }
    public function identify(string $raw,TenantContext $context):?array {
        $parsed=self::parse($raw);if($parsed===null)return null;
        $user=$this->db->row("SELECT a.id,a.user_id,a.tenant_id,a.scopes,u.name,u.email FROM bf_api_tokens a JOIN users u ON u.id=a.user_id AND u.is_active=1 WHERE a.id=? AND a.token_hash=? AND a.revoked_at IS NULL AND a.expires_at>UTC_TIMESTAMP() LIMIT 1",[$parsed['id'],$parsed['digest']]);
        if(!$user)return null;
        $tenant=$context->isTenant()?$context->requireTenantId():null;
        if(($user['tenant_id']===null?null:(int)$user['tenant_id'])!==$tenant)return null;
        if($tenant!==null){
            $member=$this->db->row("SELECT m.role FROM tenant_memberships m JOIN tenants t ON t.id=m.tenant_id WHERE m.user_id=? AND m.tenant_id=? AND t.status='active' LIMIT 1",[(int)$user['user_id'],$tenant]);
            if(!$member)return null;
            $user['role']=$member['role'];
        }
        $scopes=json_decode((string)$user['scopes'],true);
        if(!is_array($scopes)||!self::validScopes($scopes))return null;
        $user['scopes']=$scopes;
        $this->db->execute('UPDATE bf_api_tokens SET last_used_at=UTC_TIMESTAMP() WHERE id=?',[$user['id']]);
        return $user;
    }
    public function revoke(int $tokenId,int $userId):bool {
        return $this->db->execute('UPDATE bf_api_tokens SET revoked_at=UTC_TIMESTAMP() WHERE id=? AND user_id=? AND revoked_at IS NULL',[$tokenId,$userId])===1;
    }
}
