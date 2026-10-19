<?php
declare(strict_types=1);
namespace Brightery\Core\Mail;
use Brightery\Core\Database;
use Brightery\Core\Tenancy\ContextStore;
use RuntimeException;
/** Account-level email ownership proof; one-time, host + tenant bound, no auto-login. */
final class EmailVerification {
    public function __construct(private readonly Database $db,private readonly Outbox $outbox,private readonly ContextStore $contexts,private readonly TemplateCatalog $templates){}
    public static function tokenValid(string $token):bool {return (bool)preg_match('/^[a-f0-9]{64}$/D',$token);}
    public function verified(int $userId):bool {return $this->db->row('SELECT user_id FROM bf_verified_emails WHERE user_id=?',[$userId])!==null;}
    public function request(int $userId,string $origin):bool {
        if($userId<1)throw new RuntimeException('Invalid account');
        if($this->verified($userId))return false;
        if(!preg_match('~^https://[a-z0-9.-]+(?::[0-9]{2,5})?$~iD',$origin) && !((string)env('APP_ENV')==='local' && preg_match('~^http://(?:localhost|127\.0\.0\.1)(?::[0-9]{2,5})?$~D',$origin)))throw new RuntimeException('Unsafe verification origin');
        $user=$this->db->row('SELECT email FROM users WHERE id=? AND is_active=1',[$userId]);
        if(!$user)throw new RuntimeException('Account unavailable');
        $context=$this->contexts->current();$tenant=$context->isTenant()?$context->requireTenantId():null;
        $token=bin2hex(random_bytes(32));$digest=hash('sha256',$token);
        $this->db->transactional(function(Database $db)use($userId,$tenant,$digest,$origin,$token,$user):void {
            $db->execute('UPDATE bf_email_verification_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL',[$userId]);
            $db->execute('INSERT INTO bf_email_verification_tokens(user_id,tenant_id,digest,expires_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE)) ON DUPLICATE KEY UPDATE tenant_id=VALUES(tenant_id),digest=VALUES(digest),expires_at=VALUES(expires_at),used_at=NULL,created_at=UTC_TIMESTAMP()',[$userId,$tenant,$digest]);
            $message=$this->templates->render('email.verify',['url'=>$origin.'/verify-email/'.$token]);
            $this->outbox->enqueue($user['email'],$message['subject'],$message['body'],$userId);
        });
        return true;
    }
    public function consume(string $token):bool {
        if(!self::tokenValid($token))return false;
        $tenant=$this->contexts->current()->isTenant()?$this->contexts->current()->requireTenantId():null;
        return $this->db->transactional(function(Database $db)use($token,$tenant):bool {
            $row=$db->row('SELECT id,user_id,tenant_id FROM bf_email_verification_tokens WHERE digest=? AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE',[hash('sha256',$token)]);
            if(!$row || ($row['tenant_id']===null?null:(int)$row['tenant_id'])!==$tenant)return false;
            if($db->execute('UPDATE bf_email_verification_tokens SET used_at=UTC_TIMESTAMP() WHERE id=? AND used_at IS NULL',[$row['id']])!==1)return false;
            $db->execute('INSERT IGNORE INTO bf_verified_emails(user_id) VALUES(?)',[$row['user_id']]);
            $db->execute('UPDATE bf_email_verification_tokens SET used_at=UTC_TIMESTAMP() WHERE user_id=? AND used_at IS NULL',[$row['user_id']]);
            return true;
        });
    }
    public function prune():int {return $this->db->execute('DELETE FROM bf_email_verification_tokens WHERE used_at IS NOT NULL OR expires_at<UTC_TIMESTAMP()');}
}
