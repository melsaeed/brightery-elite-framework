<?php
declare(strict_types=1);
namespace Modules\System\Controllers;
use Brightery\Core\{App,Database,Security,View,RateLimiter};
use Brightery\Core\Http\{Request,Response};
use Brightery\Core\Tenancy\{ContextStore,TenantAccess,TenantPolicy,TenantMemberships,TenantInvitations};
use RuntimeException;

/** Tenant UI lives under /workspace and never inherits global admin authorization. */
final class WorkspaceController {
    private static function svc(string $class):object{return App::get()->service($class);}
    private static function context():\Brightery\Core\Tenancy\TenantContext {return self::svc(ContextStore::class)->current();}
    private static function tenant():int {return self::context()->requireTenantId();}
    private static function view(string $name,array $data=[],int $status=200):Response {
        return Response::html(self::svc(View::class)->render('workspace/'.$name,$data),$status);
    }
    private static function page(string $title,string $name,array $data=[]):Response {
        $member=self::svc(TenantAccess::class)->membership();
        $content=self::svc(View::class)->render('workspace/'.$name,$data+['csrf'=>self::svc(Security::class)->csrf(),'member'=>$member]);
        return self::view('layout',['title'=>$title,'content'=>$content,'csrf'=>self::svc(Security::class)->csrf(),'member'=>$member,'tenant'=>self::context()]);
    }
    public static function loginPage():Response {
        if(!self::context()->isTenant())return Response::html('Not found',404);
        if(self::svc(TenantAccess::class)->membership()!==null)return Response::redirect('/workspace');
        if(self::svc(Security::class)->pendingMfa()!==null)return Response::redirect('/mfa');
        return self::view('login',['csrf'=>self::svc(Security::class)->csrf(),'company'=>self::context()->slug]);
    }
    public static function login(Request $r):Response {
        if(!self::context()->isTenant())return Response::html('Not found',404);
        $email=strtolower(trim((string)$r->input('email','')));$password=(string)$r->input('password','');
        if(strlen($email)>190||strlen($password)>1024||!filter_var($email,FILTER_VALIDATE_EMAIL))return Response::html('Invalid credentials',401);
        $limiter=self::svc(RateLimiter::class);
        if(!$limiter->hit('tenant-login:'.hash('sha256',self::tenant().'|'.$r->ip().'|'.$email),5,300))return Response::html('Too many attempts',429);
        if(!self::svc(TenantAccess::class)->attempt($email,$password))return self::view('login',['csrf'=>self::svc(Security::class)->csrf(),'company'=>self::context()->slug,'error'=>'Invalid email, password, or company membership']);
        return Response::redirect(self::svc(Security::class)->pendingMfa()!==null?'/mfa':'/workspace');
    }
    public static function logout():Response {self::svc(Security::class)->logout();return Response::redirect('/workspace/login');}
    public static function dashboard():Response {
        $db=self::svc(Database::class);$id=self::tenant();
        return self::page('Overview','dashboard',['memberCount'=>(int)($db->row('SELECT COUNT(*) n FROM tenant_memberships WHERE tenant_id=?',[$id])['n']??0),'inviteCount'=>(int)($db->row('SELECT COUNT(*) n FROM tenant_invitations WHERE tenant_id=? AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP()',[$id])['n']??0)]);
    }
    public static function audit():Response {
        return self::page('Security events','audit',['events'=>self::svc(\Brightery\Core\Audit\AuditTrail::class)->recent(50)]);
    }
    public static function members():Response {
        return self::page('Team & Access','members',['members'=>self::svc(TenantMemberships::class)->list(self::tenant()),'roles'=>TenantPolicy::ROLES]);
    }
    public static function changeRole(Request $r):Response {
        $target=filter_var($r->input('user_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $role=(string)$r->input('role');
        if(!$target||!TenantPolicy::validRole($role))return Response::html('Invalid membership',422);
        try{self::svc(TenantMemberships::class)->setRole(self::tenant(),(int)self::svc(Security::class)->userId(),$target,$role);}
        catch(RuntimeException $e){return Response::html(esc($e->getMessage()),403);}
        return Response::redirect('/workspace/members');
    }
    public static function remove(Request $r):Response {
        $target=filter_var($r->input('user_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$target)return Response::html('Invalid membership',422);
        try{self::svc(TenantMemberships::class)->remove(self::tenant(),(int)self::svc(Security::class)->userId(),$target);}
        catch(RuntimeException $e){return Response::html(esc($e->getMessage()),403);}
        return Response::redirect('/workspace/members');
    }
    public static function invite(Request $r):Response {
        $role=(string)$r->input('role','viewer');$actor=self::svc(TenantAccess::class)->membership();
        if(!$actor||!TenantPolicy::mayGrant((string)$actor['role'],$role))return Response::html('Forbidden',403);
        try{self::svc(TenantInvitations::class)->issue(self::tenant(),(string)$r->input('email'),$role,(int)$actor['id'],'https://'.self::context()->hostname);}
        catch(RuntimeException $e){return Response::html(esc($e->getMessage()),422);}
        return self::issued();
    }
    public static function issued():Response {
        $returnTo=self::context()->isTenant()?'/workspace/members':'/admin/tenants';
        $response=self::view('issued',['returnTo'=>$returnTo]);
        $response->headers['Referrer-Policy']='no-referrer';
        $response->headers['Cache-Control']='private, no-store';
        return $response;
    }
    public static function showInvitation(Request $r,array $params):Response {
        if(!self::context()->isTenant())return Response::html('Not found',404);
        $token=(string)($params['token']??'');
        $invite=self::svc(TenantInvitations::class)->inspect(self::tenant(),$token);
        if(!$invite)return Response::html('Invitation not found or expired',404);
        $response=self::view('invite',['token'=>$token,'invite'=>$invite,'csrf'=>self::svc(Security::class)->csrf()]);
        $response->headers['Referrer-Policy']='no-referrer';return $response;
    }
    public static function acceptInvitation(Request $r,array $params):Response {
        if(!self::context()->isTenant())return Response::html('Not found',404);
        if(!self::svc(RateLimiter::class)->hit('invite-accept:'.hash('sha256',self::tenant().'|'.$r->ip()),15,300))return Response::html('Too many attempts',429);
        try{$userId=self::svc(TenantInvitations::class)->accept(self::tenant(),(string)($params['token']??''),(string)$r->input('name',''),(string)$r->input('password',''));}
        catch(RuntimeException $e){return Response::html(esc($e->getMessage()),422);}
        if(self::svc(\Brightery\Core\Mfa\Totp::class)->enabled($userId)) {self::svc(Security::class)->beginMfa($userId,'/workspace');return Response::redirect('/mfa');}
        self::svc(Security::class)->login($userId);return Response::redirect('/workspace');
    }
}
