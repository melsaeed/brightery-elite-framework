<?php
declare(strict_types=1);
namespace Modules\System\Controllers;
use Brightery\Core\{App,Auth,Security,RateBuckets};
use Brightery\Core\Auth\SessionRegistry;
use Brightery\Core\Mail\EmailVerification;
use Brightery\Core\Tenancy\{ContextStore,TenantAccess,HostTenantResolver};
use Brightery\Core\Http\{Request,Response};
final class AccountSecurityController {
    private static function service(string $class):object {return App::get()->service($class);}
    private static function authorized():bool {
        $sec=self::service(Security::class);if(!$sec->userId())return false;
        $ctx=self::service(ContextStore::class)->current();
        return $ctx->isTenant()?self::service(TenantAccess::class)->membership()!==null:self::service(Auth::class)->user()!==null;
    }
    private static function shell(string $heading,string $body):Response {
        $csrf=esc(self::service(Security::class)->csrf());
        $assets=self::service(\Brightery\Core\Ui\AssetManager::class);
        return Response::html('<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.esc($heading).' · Brightery</title><link rel="stylesheet" href="'.esc($assets->url('css/app.min.css')).'"></head><body class="bf-admin bf-security"><main class="bf-security-card"><div class="bf-security-content"><h1>'.esc($heading).'</h1>'.$body.'<p><a href="/account/mfa">Authenticator settings</a> · <a href="/">Home</a></p></div></main></body></html>');
    }
    public static function index():Response {
        if(!self::authorized())return Response::html('Authentication required',401);
        $user=(int)self::service(Security::class)->userId();$verified=self::service(EmailVerification::class)->verified($user);
        $sessions=self::service(SessionRegistry::class)->list($user);$csrf=esc(self::service(Security::class)->csrf());
        $html='<p>Email status: <strong>'.($verified?'Verified':'Not verified').'</strong></p>';
        if(!$verified)$html.='<form action="/account/security/email/send" method="post"><input type="hidden" name="_csrf" value="'.$csrf.'"><button>Send verification email</button></form>';
        $html.='<h2>Sessions</h2><p>'.count($sessions).' recent active sessions (maximum 100 shown).</p><form method="post" action="/account/security/sessions/revoke-others"><input type="hidden" name="_csrf" value="'.$csrf.'"><button>Sign out other sessions</button></form>';
        return self::shell('Account security',$html);
    }
    public static function send(Request $request):Response {
        if(!self::authorized())return Response::html('Authentication required',401);
        $ctx=self::service(ContextStore::class)->current();$tenant=$ctx->isTenant()?$ctx->requireTenantId():null;
        $user=(int)self::service(Security::class)->userId();
        if(!self::service(RateBuckets::class)->hit($tenant,'email.verify.send',(string)$user,3,3600))return Response::html('Verification limit reached',429);
        $host=HostTenantResolver::hostname((string)($request->server['HTTP_HOST']??''));
        $appUrl=(string)env('APP_URL','');$parsed=parse_url($appUrl);
        $origin=(string)env('APP_ENV','production')==='local' && HostTenantResolver::hostname((string)($parsed['host']??''))===$host
            ?rtrim($appUrl,'/'):'https://'.$host;
        try{$sent=self::service(EmailVerification::class)->request($user,$origin);}
        catch(\Throwable){return Response::html('Unable to queue verification email; check SMTP setup',503);}
        return self::shell('Check your inbox',$sent?'<p>Verification email queued. Open the message and confirm your email address.</p>':'<p>Your email is already verified.</p>');
    }
    public static function confirmPage(Request $request,array $params):Response {
        $token=(string)($params['token']??'');
        if(!EmailVerification::tokenValid($token))return Response::html('Invalid verification link',404);
        $csrf=esc(self::service(Security::class)->csrf());
        $response=self::shell('Confirm email address','<p>Confirming proves you can receive email at the address on your account. This action does not sign you in.</p><form method="post" action="/verify-email/'.esc($token).'"><input type="hidden" name="_csrf" value="'.$csrf.'"><button>Verify email address</button></form>');
        $response->headers['Referrer-Policy']='no-referrer';return $response;
    }
    public static function confirm(Request $request,array $params):Response {
        $verified=self::service(EmailVerification::class)->consume((string)($params['token']??''));
        return self::shell($verified?'Email verified':'Link invalid or expired',$verified?'<p>Your email is verified. You can return to your account.</p>':'<p>Request a new email verification from your account settings.</p>');
    }
    public static function revokeOthers():Response {
        if(!self::authorized())return Response::html('Authentication required',401);
        $id=(int)self::service(Security::class)->userId();
        self::service(SessionRegistry::class)->revokeOthers($id,session_id());
        return Response::redirect('/account/security');
    }
}
