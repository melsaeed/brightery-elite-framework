<?php
declare(strict_types=1);
namespace Brightery\Core\Audit;
use Brightery\Core\Database;
use Brightery\Core\Tenancy\ContextStore;
use RuntimeException;
/** Append-only at application level. Database administrators can still mutate rows. */
final class AuditTrail {
    public function __construct(private readonly Database $db,private readonly ContextStore $contexts){}
    public static function sanitize(array $metadata,int $depth=0):array {
        if($depth>5)return ['truncated'=>'depth'];
        $result=[];
        foreach(array_slice($metadata,0,40,true) as $key=>$value){
            if(preg_match('/pass(word)?|secret|token|credential|authorization|cookie|otp|key|csrf|session/i',(string)$key)){$result[$key]='[REDACTED]';continue;}
            if(is_array($value))$result[$key]=self::sanitize($value,$depth+1);
            elseif(is_scalar($value)||$value===null)$result[$key]=is_string($value)?substr($value,0,1000):$value;
            else $result[$key]='[UNSUPPORTED]';
        }
        return $result;
    }
    public function record(string $action,?int $actorId=null,?string $subjectType=null,?string $subjectId=null,array $metadata=[]):int {
        if(!preg_match('/^[a-z][a-z0-9_.-]{1,99}$/D',$action))throw new RuntimeException('Invalid audit action');
        $context=$this->contexts->current();
        $tenantId=$context->isTenant()?$context->requireTenantId():null;
        $json=json_encode(self::sanitize($metadata),JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE);
        if(strlen($json)>10000)throw new RuntimeException('Audit metadata too large');
        $this->db->execute('INSERT INTO bf_audit_logs(tenant_id,actor_id,action,subject_type,subject_id,metadata) VALUES(?,?,?,?,?,?)',[$tenantId,$actorId,$action,$subjectType,$subjectId,$json]);
        return (int)$this->db->pdo()->lastInsertId();
    }
    public function recent(int $limit=50):array {
        $ctx=$this->contexts->current();$limit=max(1,min($limit,100));
        if($ctx->isTenant())return $this->db->rows('SELECT id,actor_id,action,subject_type,subject_id,metadata,occurred_at FROM bf_audit_logs WHERE tenant_id=? ORDER BY id DESC LIMIT '.$limit,[$ctx->requireTenantId()]);
        return $this->db->rows('SELECT id,tenant_id,actor_id,action,subject_type,subject_id,metadata,occurred_at FROM bf_audit_logs WHERE tenant_id IS NULL ORDER BY id DESC LIMIT '.$limit);
    }
}
