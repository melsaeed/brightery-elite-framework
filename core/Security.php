<?php
declare(strict_types=1);
namespace Brightery\Core;
use Brightery\Core\Http\Request;
final class Security {
    private ?string $checked=null;
    private bool $sessionValid=false;
    public function resetRequest():void {$this->checked=null;$this->sessionValid=false;}
    private function tenantId():?int {
        try{$context=App::get()->service(\Brightery\Core\Tenancy\ContextStore::class)->current();return $context->isTenant()?$context->requireTenantId():null;}
        catch(\LogicException){return null;}
    }
    private function registry():\Brightery\Core\Auth\SessionRegistry {return App::get()->service(\Brightery\Core\Auth\SessionRegistry::class);}
    public function startSession(): void {
        if(session_status()===PHP_SESSION_ACTIVE)return;
        $secure=(string)env('SESSION_SECURE','false')==='true' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off');
        ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');
        session_name($secure?'__Host-bf_session':'bf_session');
        session_set_cookie_params(['lifetime'=>0,'path'=>'/','domain'=>'','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);
        session_start();
        $now=time();
        if(isset($_SESSION['last_seen']) && ($now-(int)$_SESSION['last_seen']>1800 || $now-(int)($_SESSION['created_at']??$now)>43200)) {
            $_SESSION=[];session_regenerate_id(true);$this->resetRequest();
        }
        $_SESSION['created_at']??=$now; $_SESSION['last_seen']=$now;
    }
    public function csrf(): string { $this->startSession();return $_SESSION['csrf']??=bin2hex(random_bytes(32)); }
    public function verifyCsrf(Request $request): bool {
        $this->startSession();$token=$request->header('X-CSRF-Token') ?: (string)($request->post['_csrf']??'');
        return is_string($token) && $token!=='' && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'],$token);
    }
    public function login(int $userId): void {
        $this->startSession();session_regenerate_id(true);unset($_SESSION['pending_mfa']);
        $_SESSION['user_id']=$userId;$_SESSION['csrf']=bin2hex(random_bytes(32));$_SESSION['created_at']=time();
        if((string)env('SESSION_REGISTRY_ENABLED','true')==='true')$this->registry()->record(session_id(),$userId,$this->tenantId());
        $this->resetRequest();
    }
    /** Password stage never creates an authenticated user session when TOTP is enabled. */
    public function beginMfa(int $userId,string $returnTo):void {
        if(!in_array($returnTo,['/admin','/workspace'],true))throw new \RuntimeException('Invalid MFA return destination');
        $this->startSession();session_regenerate_id(true);
        unset($_SESSION['user_id']);
        $_SESSION['pending_mfa']=['id'=>$userId,'return'=>$returnTo,'host'=>(string)($_SERVER['HTTP_HOST']??''),'started'=>time()];
        $_SESSION['csrf']=bin2hex(random_bytes(32));
    }
    public function pendingMfa():?array {
        $this->startSession();$pending=$_SESSION['pending_mfa']??null;
        if(!is_array($pending)||time()-(int)($pending['started']??0)>300||$pending['host']!==(string)($_SERVER['HTTP_HOST']??'')){
            unset($_SESSION['pending_mfa']);return null;
        }
        return $pending;
    }
    public function completeMfa():void {
        $pending=$this->pendingMfa();if($pending===null)throw new \RuntimeException('MFA challenge expired');
        unset($_SESSION['pending_mfa']);$this->login((int)$pending['id']);
    }
    public function logout(): void {
        $this->startSession();
        if(isset($_SESSION['user_id'])&&(string)env('SESSION_REGISTRY_ENABLED','true')==='true')$this->registry()->revoke(session_id());
        $_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));$this->resetRequest();
    }
    public function userId():?int {
        $this->startSession();if(!isset($_SESSION['user_id']))return null;
        $user=(int)$_SESSION['user_id'];
        if((string)env('SESSION_REGISTRY_ENABLED','true')!=='true')return $user;
        $sid=session_id();$key=$sid.'|'.$user.'|'.($this->tenantId()??'central');
        if($this->checked!==$key){$this->checked=$key;$this->sessionValid=$this->registry()->valid($sid,$user,$this->tenantId());}
        if(!$this->sessionValid){unset($_SESSION['user_id']);return null;}
        return $user;
    }
    public function headers(): array {
        $headers=['X-Content-Type-Options'=>'nosniff','X-Frame-Options'=>'DENY','Referrer-Policy'=>'strict-origin-when-cross-origin','Permissions-Policy'=>'camera=(), microphone=(), geolocation=()','Content-Security-Policy'=>"default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'",'Cross-Origin-Resource-Policy'=>'same-origin'];
        if((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off') || (string)env('SESSION_SECURE')==='true')$headers['Strict-Transport-Security']='max-age=31536000';
        return $headers;
    }
    public function validateHost(Request $request): bool {
        $trusted=array_filter(array_map('trim',explode(',',(string)env('TRUSTED_HOSTS','localhost,127.0.0.1'))));
        $host=\Brightery\Core\Tenancy\HostTenantResolver::hostname((string)($request->server['HTTP_HOST']??'localhost'));
        if($host==='')return false;
        if(in_array($host,$trusted,true))return true;
        // A syntactically valid host is not trusted as a tenant until the exact DB lookup succeeds.
        return (string)env('TENANCY_MODE','single')==='multi' && $host!==\Brightery\Core\Tenancy\HostTenantResolver::hostname((string)env('TENANCY_CONTROL_HOST',''));
    }
}
