<?php
declare(strict_types=1);
namespace Modules\System\Controllers;
use Brightery\Core\{App,Security,RateLimiter,Auth,Database};
use Brightery\Core\Mfa\{Totp,RecoveryCodes};
use Brightery\Core\Http\{Request,Response};
use Brightery\Core\Tenancy\{ContextStore,TenantAccess};
final class AccountMfaController {
    private static function sec():Security{return App::get()->service(Security::class);}
    private static function authorized():bool {
        $user=self::sec()->userId();if(!$user)return false;
        $ctx=App::get()->service(ContextStore::class)->current();
        if($ctx->isTenant())return App::get()->service(TenantAccess::class)->membership()!==null;
        // Central/single host requires a valid authenticated account; not inherited into tenant permissions.
        return App::get()->service(Auth::class)->user()!==null;
    }
    private static function chrome(string $title,string $content):Response {
        $assets=App::get()->service(\Brightery\Core\Ui\AssetManager::class);
        $css=esc($assets->url('css/app.min.css'));
        $js=esc($assets->url('js/app.js'));
        return Response::html('<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light dark"><title>'.esc($title).' · Brightery</title><link rel="stylesheet" href="'.$css.'"><script defer src="'.$js.'"></script></head><body class="bf-admin bf-security"><div class="bf-security-card"><header class="bf-security-brand"><span class="brand-glyph">B<span>·</span></span><div><strong>Brightery Framework</strong><small>SECURE ACCOUNT ACCESS</small></div><button type="button" class="icon-button" data-theme-switch aria-label="Toggle dark mode">◐</button></header><div class="bf-security-content">'.$content.'</div><footer>Encrypted credentials · Time-based authentication</footer></div></body></html>');
    }
    private static function page(string $title,string $message='',string $secret='',bool $locked=false):Response {
        $sec=self::sec();$csrf=esc($sec->csrf());$secretHtml=$secret!==''?'<div class="notice">Save this authenticator secret securely. It will not be displayed again.</div><code>'.esc($secret).'</code>':'';
        $form=$locked?'':($secret===''?'<form method="post" action="/account/mfa/enroll"><input type="hidden" name="_csrf" value="'.$csrf.'"><button>Generate authenticator secret</button></form>':'<form method="post" action="/account/mfa/activate"><input type="hidden" name="_csrf" value="'.$csrf.'"><input name="code" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required placeholder="6-digit code"><button>Enable MFA</button></form>');
        $body='<h1>'.esc($title).'</h1><p class="muted">'.esc($message).'</p>'.$secretHtml.$form.'<p class="muted"><a href="/">← Return home</a></p>';
        return self::chrome($title,$body);
    }
    public static function pageMfa():Response {
        if(!self::authorized())return Response::html('Forbidden',403);
        if(App::get()->service(Totp::class)->enabled(self::sec()->userId())){
            $csrf=esc(self::sec()->csrf());
            return self::chrome('MFA is enabled','<h1>MFA is enabled</h1><p class="muted">Your account uses an authenticator and one-time recovery codes.</p><form method="post" action="/account/mfa/recovery/rotate"><input type="hidden" name="_csrf" value="'.$csrf.'"><label>Authenticator code to replace recovery codes</label><input name="code" maxlength="6" pattern="[0-9]{6}" required><button>Replace recovery codes</button></form>');
        }
        return self::page('Enable two-factor authentication','Generate a secret, add it to your authenticator application and confirm a 6-digit code.');
    }
    public static function enroll():Response {
        if(!self::authorized())return Response::html('Forbidden',403);
        $sec=self::sec();
        if(!App::get()->service(RateLimiter::class)->hit('mfa-enroll:'.$sec->userId(),5,600))return Response::html('Too many attempts',429);
        try{$secret=App::get()->service(Totp::class)->enroll($sec->userId());}
        catch(\Throwable){return Response::html('Cannot enroll account',409);}
        $response=self::page('Add to authenticator','Enter the following secret in your authenticator app, then confirm.', $secret);
        $response->headers['Cache-Control']='private, no-store';$response->headers['Referrer-Policy']='no-referrer';return $response;
    }
    public static function activate(Request $request):Response {
        if(!self::authorized())return Response::html('Forbidden',403);
        if(!App::get()->service(RateLimiter::class)->hit('mfa-activate:'.self::sec()->userId(),10,600))return Response::html('Too many attempts',429);
        if(!App::get()->service(Totp::class)->activate(self::sec()->userId(),(string)$request->input('code')))return Response::html('Invalid verification code',422);
        $codes=App::get()->service(RecoveryCodes::class)->rotate((int)self::sec()->userId());
        return self::showRecovery($codes);
    }
    private static function showRecovery(array $codes):Response {
        $markup='<h1>Save your recovery codes</h1><p class="muted">Each code works only once. Store these offline; they will not be shown again.</p><pre>'.esc(implode("\n",$codes)).'</pre><p><a href="/account/mfa">Continue</a></p>';
        $response=self::chrome('Recovery codes',$markup);$response->headers['Cache-Control']='private, no-store';$response->headers['Referrer-Policy']='no-referrer';return $response;
    }
    public static function rotateRecovery(Request $request):Response {
        if(!self::authorized())return Response::html('Forbidden',403);
        $user=(int)self::sec()->userId();
        if(!App::get()->service(RateLimiter::class)->hit('mfa-rotate:'.$user,5,3600))return Response::html('Too many attempts',429);
        if(!App::get()->service(Totp::class)->verify($user,(string)$request->input('code')))return Response::html('Invalid authenticator code',422);
        return self::showRecovery(App::get()->service(RecoveryCodes::class)->rotate($user));
    }
    public static function challengePage():Response {
        if(self::sec()->pendingMfa()===null)return Response::html('Authentication required',401);
        $csrf=esc(self::sec()->csrf());
        return self::chrome('Two-factor verification','<h1>Verify your identity</h1><p class="muted">Enter an authenticator code or one of your saved recovery codes.</p><form method="post" action="/mfa"><input type="hidden" name="_csrf" value="'.$csrf.'"><label for="totp-code">Authenticator code</label><input id="totp-code" name="code" autocomplete="one-time-code" inputmode="numeric" maxlength="43" required autofocus placeholder="Code or recovery code"><button class="btn btn-primary">Continue securely</button></form>');
    }
    public static function challenge(Request $request):Response {
        $pending=self::sec()->pendingMfa();if(!$pending)return Response::html('Authentication expired',401);
        if(!App::get()->service(RateLimiter::class)->hit('mfa-login:'.$pending['id'].':'.hash('sha256',$request->ip()),10,300))return Response::html('Too many attempts',429);
        $db=App::get()->service(Database::class);
        $active=$db->row('SELECT id FROM users WHERE id=? AND is_active=1',[$pending['id']]);
        if(!$active)return Response::html('Authentication expired',401);
        $context=App::get()->service(ContextStore::class)->current();
        if($pending['return']==='/workspace'){
            if(!$context->isTenant()||!$db->row("SELECT 1 FROM tenant_memberships m JOIN tenants t ON t.id=m.tenant_id WHERE m.user_id=? AND m.tenant_id=? AND t.status='active' LIMIT 1",[$pending['id'],$context->requireTenantId()]))return Response::html('Authentication expired',401);
        }elseif($context->isTenant())return Response::html('Authentication expired',401);
        $code=(string)$request->input('code');
        if(!App::get()->service(Totp::class)->verify($pending['id'],$code) && !App::get()->service(RecoveryCodes::class)->consume($pending['id'],$code))return Response::html('Invalid verification code',422);
        self::sec()->completeMfa();return Response::redirect($pending['return']);
    }
}
