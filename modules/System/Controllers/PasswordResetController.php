<?php
declare(strict_types=1);
namespace Modules\System\Controllers;
use Brightery\Core\{App,Security,RateBuckets};
use Brightery\Core\Http\{Request,Response};
use Brightery\Core\Mail\PasswordReset;
use Brightery\Core\Tenancy\{ContextStore,HostTenantResolver};
final class PasswordResetController {
    private static function svc(string $name):object {return App::get()->service($name);}
    private static function page(string $heading,string $content):Response {
        $asset=self::svc(\Brightery\Core\Ui\AssetManager::class)->url('css/app.min.css');
        $csrf=esc(self::svc(Security::class)->csrf());
        $body='<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="referrer" content="no-referrer"><title>'.esc($heading).' · Brightery</title><link rel="stylesheet" href="'.esc($asset).'"></head><body class="bf-admin"><main class="bf-security-card"><section class="panel"><h1>'.esc($heading).'</h1>'.$content.'<p><a href="/workspace/login">Workspace login</a> · <a href="/admin/login">Admin login</a></p></section></main></body></html>';
        $r=Response::html($body);$r->headers['Referrer-Policy']='no-referrer';$r->headers['Cache-Control']='private, no-store';return $r;
    }
    public static function requestForm():Response {
        return self::page('Reset password','<p>Enter your account email. If an eligible account exists, we will email a reset link.</p><form method="post" action="/forgot-password"><input type="hidden" name="_csrf" value="'.esc(self::svc(Security::class)->csrf()).'"><label class="bf-field"><span>Email</span><input type="email" name="email" maxlength="190" required autocomplete="email"></label><button class="btn btn-primary" type="submit">Send reset link</button></form>');
    }
    public static function request(Request $request):Response {
        $raw=(string)$request->input('email','');
        $ctx=self::svc(ContextStore::class)->current();
        $scope=$ctx->isTenant()?$ctx->requireTenantId():'central';
        $hash=hash('sha256',$scope.'|'.$request->ip().'|'.strtolower(trim($raw)));
        if(!self::svc(RateBuckets::class)->hit($ctx->isTenant()?$ctx->requireTenantId():null,'password.reset.request',$hash,3,3600))return self::success();
        $host=HostTenantResolver::hostname((string)($request->server['HTTP_HOST']??''));
        $configured=(string)env('APP_URL','');$parsed=parse_url($configured);
        $origin=(string)env('APP_ENV','production')==='local' && HostTenantResolver::hostname((string)($parsed['host']??''))===$host?rtrim($configured,'/'):'https://'.$host;
        try{self::svc(PasswordReset::class)->request($raw,$origin);}
        catch(\Throwable $error){error_log('Password reset delivery unavailable: '.$error::class);}
        return self::success();
    }
    private static function success():Response {return self::page('Check your inbox','<p>If the account is eligible, a password reset link has been queued. Check your email.</p>');}
    public static function resetForm(Request $request,array $params):Response {
        $token=(string)($params['token']??'');if(!PasswordReset::validToken($token))return Response::html('Not found',404);
        return self::page('Choose a new password','<form method="post" action="/reset-password/'.esc($token).'"><input type="hidden" name="_csrf" value="'.esc(self::svc(Security::class)->csrf()).'"><label class="bf-field"><span>New password (12+ characters)</span><input type="password" name="password" minlength="12" maxlength="256" required autocomplete="new-password"></label><label class="bf-field"><span>Confirm new password</span><input type="password" name="confirm" minlength="12" maxlength="256" required autocomplete="new-password"></label><button class="btn btn-primary" type="submit">Change password</button></form>');
    }
    public static function reset(Request $request,array $params):Response {
        $pass=(string)$request->input('password','');$confirm=(string)$request->input('confirm','');
        if(!hash_equals($pass,$confirm)||strlen($pass)<12||strlen($pass)>256)return self::page('Invalid password','<p>Use matching passwords of 12–256 characters.</p>');
        $ctx=self::svc(ContextStore::class)->current();$tenant=$ctx->isTenant()?$ctx->requireTenantId():null;
        if(!self::svc(RateBuckets::class)->hit($tenant,'password.reset.consume',hash('sha256',$request->ip()),8,900))return Response::html('Try again later',429);
        try{$ok=self::svc(PasswordReset::class)->consume((string)($params['token']??''),$pass);}
        catch(\Throwable){return self::page('Try again','<p>The request could not be completed.</p>');}
        return self::page($ok?'Password changed':'Link invalid or expired',$ok?'<p>Your password is updated. All existing sessions and API tokens have been revoked. Sign in again.</p>':'<p>Request another reset link from the recovery page.</p>');
    }
}
