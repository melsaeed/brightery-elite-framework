<?php
declare(strict_types=1);
namespace Brightery\Core\Mfa;
use Brightery\Core\Database;
use RuntimeException;
/** SHA-256 digests of 128-bit random one-time recovery secrets. */
final class RecoveryCodes {
    public function __construct(private readonly Database $db){}
    public static function format(string $hex):string {
        if(!preg_match('/^[a-f0-9]{32}$/D',$hex))throw new RuntimeException('Invalid recovery secret');
        return implode('-',str_split(strtoupper($hex),8));
    }
    public static function digest(string $entered):?string {
        $plain=strtolower(str_replace(['-',' '],'',trim($entered)));
        return preg_match('/^[0-9a-f]{32}$/D',$plain)?hash('sha256',$plain):null;
    }
    /** Rotate all recovery codes. The caller must already prove authenticator possession. */
    public function rotate(int $userId,int $count=8):array {
        if($userId<1||$count<4||$count>16)throw new RuntimeException('Invalid recovery code request');
        $raw=[];for($i=0;$i<$count;$i++)$raw[]=bin2hex(random_bytes(16));
        $this->db->transactional(function(Database $db)use($userId,$raw):void {
            $db->execute('DELETE FROM bf_mfa_recovery_codes WHERE user_id=?',[$userId]);
            foreach($raw as $token)$db->execute('INSERT INTO bf_mfa_recovery_codes(user_id,digest) VALUES(?,?)',[$userId,hash('sha256',$token)]);
        });
        return array_map([self::class,'format'],$raw);
    }
    public function consume(int $userId,string $entered):bool {
        $digest=self::digest($entered);
        if($userId<1||$digest===null)return false;
        // A unique row and conditional update reject concurrent replays atomically.
        return $this->db->execute('UPDATE bf_mfa_recovery_codes SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND digest=? AND consumed_at IS NULL',[$userId,$digest])===1;
    }
    public function remaining(int $userId):int {return (int)($this->db->row('SELECT COUNT(*) n FROM bf_mfa_recovery_codes WHERE user_id=? AND consumed_at IS NULL',[$userId])['n']??0);}
    public function revoke(int $userId):void {$this->db->execute('DELETE FROM bf_mfa_recovery_codes WHERE user_id=?',[$userId]);}
}
