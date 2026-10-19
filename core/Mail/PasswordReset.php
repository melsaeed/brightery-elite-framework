<?php
declare(strict_types=1);
namespace Brightery\Core\Mail;
use Brightery\Core\Database;
use Brightery\Core\Tenancy\ContextStore;
use RuntimeException;
/** No user enumeration; one-time password reset with credential/session/token revocation. */
class PasswordReset {
    public function __construct(private readonly Database $db, private readonly Outbox $mail, private readonly ContextStore $context,private readonly TemplateCatalog $templates){}
    public static function validToken(string $token):bool {return (bool)preg_match('/^[a-f0-9]{64}$/D',$token);}
    public static function validOrigin(string $origin):bool {
        return (bool)preg_match('~^https://[a-z0-9.-]+(?::[0-9]{2,5})?$~iD',$origin)
            || ((string)env('APP_ENV','production')==='local' && (bool)preg_match('~^http://(?:localhost|127\.0\.0\.1)(?::[0-9]{2,5})?$~D',$origin));
    }
    private function tenant():?int { $ctx=$this->context->current();return $ctx->isTenant()?$ctx->requireTenantId():null; }
    public function request(string $email,string $origin):void {
        if(!self::validOrigin($origin))throw new RuntimeException('Unsafe password reset origin');
        $email=strtolower(trim($email));
        if(strlen($email)>190||!filter_var($email,FILTER_VALIDATE_EMAIL))return;
        $tenant=$this->tenant();
        $user=$tenant===null
            ?$this->db->row('SELECT id,email FROM users WHERE email=? AND is_active=1 LIMIT 1',[$email])
            :$this->db->row("SELECT u.id,u.email FROM users u JOIN tenant_memberships m ON m.user_id=u.id JOIN tenants t ON t.id=m.tenant_id AND t.status='active' WHERE u.email=? AND u.is_active=1 AND m.tenant_id=? LIMIT 1",[$email,$tenant]);
        if(!$user)return;
        $raw=bin2hex(random_bytes(32));$digest=hash('sha256',$raw);
        $this->db->transactional(function(Database $db)use($user,$tenant,$digest,$raw,$origin):void {
            $db->execute('INSERT INTO bf_password_reset_tokens(user_id,tenant_id,digest,expires_at) VALUES(?,?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 20 MINUTE)) ON DUPLICATE KEY UPDATE tenant_id=VALUES(tenant_id),digest=VALUES(digest),expires_at=VALUES(expires_at),consumed_at=NULL,created_at=UTC_TIMESTAMP()',[(int)$user['id'],$tenant,$digest]);
            $message=$this->templates->render('password.reset',['url'=>$origin.'/reset-password/'.$raw]);
            $this->mail->enqueue((string)$user['email'],$message['subject'],$message['body'],(int)$user['id']);
        });
    }
    /** Transaction + row lock: same token cannot be accepted twice; no auto-login. */
    public function consume(string $raw,string $password):bool {
        if(!self::validToken($raw)||strlen($password)<12||strlen($password)>256)return false;
        $hash=password_hash($password,PASSWORD_ARGON2ID);
        $tenant=$this->tenant();
        return $this->db->transactional(function(Database $db)use($raw,$hash,$tenant):bool {
            $row=$db->row('SELECT user_id,tenant_id FROM bf_password_reset_tokens WHERE digest=? AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP() FOR UPDATE',[hash('sha256',$raw)]);
            if(!$row||($row['tenant_id']===null?null:(int)$row['tenant_id'])!==$tenant)return false;
            if($db->execute('UPDATE bf_password_reset_tokens SET consumed_at=UTC_TIMESTAMP() WHERE user_id=? AND consumed_at IS NULL',[(int)$row['user_id']])!==1)return false;
            if($db->execute('UPDATE users SET password_hash=? WHERE id=? AND is_active=1',[$hash,(int)$row['user_id']])!==1)throw new RuntimeException('Account unavailable');
            $db->execute('UPDATE bf_account_sessions SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL',[(int)$row['user_id']]);
            $db->execute('UPDATE bf_api_tokens SET revoked_at=UTC_TIMESTAMP() WHERE user_id=? AND revoked_at IS NULL',[(int)$row['user_id']]);
            return true;
        });
    }
    public function prune():int{return $this->db->execute('DELETE FROM bf_password_reset_tokens WHERE consumed_at IS NOT NULL OR expires_at<UTC_TIMESTAMP()');}
}
