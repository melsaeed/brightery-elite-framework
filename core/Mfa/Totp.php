<?php
declare(strict_types=1);
namespace Brightery\Core\Mfa;
use Brightery\Core\Database;
use Brightery\Core\Crypto\Encrypter;
use RuntimeException;
/** RFC 6238 SHA1 TOTP; encrypted secrets at rest; replay prevention via last accepted counter. */
final class Totp {
    public function __construct(private readonly Database $db,private readonly Encrypter $encryption){}
    public function enabled(int $userId):bool {
        if($userId<1)return false;
        return $this->db->row('SELECT user_id FROM bf_totp_credentials WHERE user_id=? AND enabled_at IS NOT NULL',[$userId])!==null;
    }
    public function enroll(int $userId):string {
        if($userId<1||$this->enabled($userId))throw new RuntimeException('Already enrolled or unknown account');
        $secret=self::encodeBase32(random_bytes(20));
        $this->db->execute('INSERT INTO bf_totp_credentials(user_id,secret_cipher,enabled_at,last_counter) VALUES(?,?,NULL,-1) ON DUPLICATE KEY UPDATE secret_cipher=IF(enabled_at IS NULL,VALUES(secret_cipher),secret_cipher),last_counter=IF(enabled_at IS NULL,-1,last_counter)',[$userId,$this->encryption->encrypt($secret)]);
        return $secret;
    }
    public function activate(int $userId,string $code):bool {
        return $this->checkAndConsume($userId,$code,false);
    }
    public function verify(int $userId,string $code):bool {
        return $this->checkAndConsume($userId,$code,true);
    }
    private function checkAndConsume(int $userId,string $code,bool $mustBeEnabled):bool {
        if(!preg_match('/^[0-9]{6}$/D',$code)||$userId<1)return false;
        $row=$this->db->row('SELECT secret_cipher,enabled_at,last_counter FROM bf_totp_credentials WHERE user_id=?',[$userId]);
        if(!$row||($mustBeEnabled && $row['enabled_at']===null)||(!$mustBeEnabled && $row['enabled_at']!==null))return false;
        try{$secret=$this->encryption->decrypt($row['secret_cipher']);$bytes=self::decodeBase32($secret);}catch(\Throwable){return false;}
        $counter=intdiv(time(),30);
        foreach([$counter-1,$counter,$counter+1] as $step){
            if($step<0||$step<=(int)$row['last_counter']||!hash_equals(self::code($bytes,$step),$code))continue;
            if($mustBeEnabled){$affected=$this->db->execute('UPDATE bf_totp_credentials SET last_counter=? WHERE user_id=? AND enabled_at IS NOT NULL AND last_counter < ?',[$step,$userId,$step]);}
            else {$affected=$this->db->execute('UPDATE bf_totp_credentials SET last_counter=?,enabled_at=UTC_TIMESTAMP() WHERE user_id=? AND enabled_at IS NULL AND last_counter < ?',[$step,$userId,$step]);}
            return $affected===1;
        }
        return false;
    }
    public static function code(string $bytes,int $counter):string {
        if($counter<0||strlen($bytes)<10)throw new RuntimeException('Invalid TOTP secret');
        $mac=hash_hmac('sha1',pack('N2',intdiv($counter,4294967296),$counter%4294967296),$bytes,true);
        $offset=ord($mac[19])&0x0F;
        $number=(unpack('N',substr($mac,$offset,4))[1]&0x7fffffff)%1000000;
        return str_pad((string)$number,6,'0',STR_PAD_LEFT);
    }
    public static function encodeBase32(string $bytes):string {
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits=0;$value=0;$out='';
        foreach(str_split($bytes) as $byte){$value=($value<<8)|ord($byte);$bits+=8;while($bits>=5){$bits-=5;$out.=$alphabet[($value>>$bits)&31];}}
        if($bits>0)$out.=$alphabet[($value<<(5-$bits))&31];
        return $out;
    }
    public static function decodeBase32(string $encoded):string {
        if(!preg_match('/^[A-Z2-7]{16,128}$/D',$encoded))throw new RuntimeException('Invalid base32 secret');
        $alphabet='ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';$bits=0;$value=0;$out='';
        foreach(str_split($encoded) as $char){$i=strpos($alphabet,$char);$value=($value<<5)|$i;$bits+=5;if($bits>=8){$bits-=8;$out.=chr(($value>>$bits)&255);}}
        return $out;
    }
}
