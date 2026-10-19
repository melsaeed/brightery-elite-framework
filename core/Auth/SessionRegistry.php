<?php
declare(strict_types=1);
namespace Brightery\Core\Auth;
use Brightery\Core\Database;
/** Server-side revocable session inventory; complements PHP's normal signed-in session. */
final class SessionRegistry {
    public function __construct(private readonly Database $db){}
    public static function digest(string $sessionId):string {
        if($sessionId==='')throw new \InvalidArgumentException('Empty session identifier');
        return hash('sha256',$sessionId);
    }
    public function record(string $sessionId,int $userId,?int $tenantId):void {
        $this->db->execute('INSERT INTO bf_account_sessions(session_digest,user_id,tenant_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),tenant_id=VALUES(tenant_id),revoked_at=NULL,last_seen_at=UTC_TIMESTAMP()', [self::digest($sessionId),$userId,$tenantId]);
    }
    public function valid(string $sessionId,int $userId,?int $tenantId):bool {
        $row=$this->db->row('SELECT user_id,tenant_id FROM bf_account_sessions WHERE session_digest=? AND revoked_at IS NULL AND last_seen_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) AND created_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 12 HOUR)',[self::digest($sessionId)]);
        if(!$row || (int)$row['user_id']!==$userId || ($row['tenant_id']===null?null:(int)$row['tenant_id'])!==$tenantId)return false;
        $this->db->execute('UPDATE bf_account_sessions SET last_seen_at=UTC_TIMESTAMP() WHERE session_digest=?', [self::digest($sessionId)]);
        return true;
    }
    public function revoke(string $sessionId):void {if($sessionId!=='')$this->db->execute('UPDATE bf_account_sessions SET revoked_at=UTC_TIMESTAMP() WHERE session_digest=?',[self::digest($sessionId)]);}
    public function revokeOthers(int $userId,string $keepId):int {return $this->db->execute('UPDATE bf_account_sessions SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND session_digest<>? AND revoked_at IS NULL',[$userId,self::digest($keepId)]);}
    public function list(int $userId):array {return $this->db->rows('SELECT session_digest,tenant_id,created_at,last_seen_at FROM bf_account_sessions WHERE user_id=? AND revoked_at IS NULL AND last_seen_at>DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE) ORDER BY last_seen_at DESC LIMIT 100',[$userId]);}
    public function prune():int{return $this->db->execute('DELETE FROM bf_account_sessions WHERE revoked_at IS NOT NULL OR created_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 12 HOUR) OR last_seen_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)');}
}
