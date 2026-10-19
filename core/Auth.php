<?php
declare(strict_types=1);
namespace Brightery\Core;
use Brightery\Core\Http\Request;use Brightery\Core\Http\Response;
final class Auth {
    public function __construct(private readonly Database $db,private readonly Security $security) {}
    public function id(): ?int {return $this->security->userId();}
    public function attempt(string $email,string $password): bool {
        $user=$this->db->row('SELECT id,password_hash,is_active FROM users WHERE email=? LIMIT 1', [strtolower(trim($email))]);
        if (!$user || !(bool)$user['is_active'] || !password_verify($password,$user['password_hash'])) return false;
        if(password_needs_rehash($user['password_hash'],PASSWORD_ARGON2ID)){
           $this->db->execute('UPDATE users SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_ARGON2ID),$user['id']]);
        }
        if (\Brightery\Core\App::get()->service(\Brightery\Core\Mfa\Totp::class)->enabled((int)$user['id'])) $this->security->beginMfa((int)$user['id'],'/admin');
        else $this->security->login((int)$user['id']);return true;
    }
    public function user(): ?array {return $this->id()===null?null:$this->db->row('SELECT id,name,email,is_active FROM users WHERE id=? AND is_active=1',[$this->id()]);}
    public function can(string $permission): bool {
        if($this->id()===null)return false;
        return (bool)$this->db->row('SELECT 1 FROM users u JOIN user_groups ug ON ug.user_id=u.id JOIN group_permissions gp ON gp.group_id=ug.group_id JOIN permissions p ON p.id=gp.permission_id WHERE u.is_active=1 AND ug.user_id=? AND (p.code=? OR p.code=\'*\') LIMIT 1',[$this->id(),$permission]);
    }
    public function guard(string $permission): callable {
        return function(Request $request,callable $next)use($permission): Response {
            if($this->id()===null)return Response::redirect('/admin/login');
            if(!$this->can($permission))return Response::html('Forbidden',403);
            return $next();
        };
    }
}
