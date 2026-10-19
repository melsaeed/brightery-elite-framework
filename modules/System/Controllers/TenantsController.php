<?php
declare(strict_types=1);
namespace Modules\System\Controllers;
use Brightery\Core\App;
use Brightery\Core\Database;
use Brightery\Core\View;
use Brightery\Core\Security;
use Brightery\Core\Media\MediaQuota;
use Brightery\Core\I18n\Translator;
use Brightery\Core\Http\{Request,Response};
use Brightery\Core\Tenancy\{HostTenantResolver,TenantInvitations,TenantPolicy};
use Modules\System\Controllers\WorkspaceController;
use Throwable;

/** Global control plane only. Tenant app/admin requires a separate permission model. */
final class TenantsController {
    private static function admin(string $title,string $page,array $values=[]):Response {
        $k=App::get();$view=$k->service(View::class);$security=$k->service(Security::class);
        $data=$values+['csrf'=>$security->csrf(),'user'=>$k->service(\Brightery\Core\Auth::class)->user(),'translator'=>$k->service(Translator::class)];
        $content=$view->render($page,$data);
        return Response::html($view->render('admin/layout',['title'=>$title,'content'=>$content,...$data]));
    }
    public static function index():Response {
        $db=App::get()->service(Database::class);
        return self::admin('Tenants','admin/tenants',[
            'tenants'=>$db->rows('SELECT t.id,t.slug,t.display_name,t.status, GROUP_CONCAT(d.hostname ORDER BY d.hostname SEPARATOR ", ") domains FROM tenants t LEFT JOIN tenant_domains d ON d.tenant_id=t.id GROUP BY t.id ORDER BY t.id DESC LIMIT 100'),
            'isMulti'=>(string)env('TENANCY_MODE','single')==='multi'
        ]);
    }
    public static function create(Request $request):Response {
        $name=trim((string)$request->input('name'));
        $slug=strtolower(trim((string)$request->input('slug')));
        if(strlen($name)<2||strlen($name)>160||!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,78}[a-z0-9])?$/D',$slug))return Response::html('Invalid company name or slug',422);
        try{App::get()->service(Database::class)->execute('INSERT INTO tenants(slug,display_name) VALUES(?,?)',[$slug,$name]);}
        catch(Throwable){return Response::html('Could not create company. Slug may already be in use.',409);}
        return Response::redirect('/admin/tenants');
    }
    public static function domain(Request $request):Response {
        $tenantId=filter_var($request->input('tenant_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $hostname=HostTenantResolver::hostname((string)$request->input('hostname'));
        $control=HostTenantResolver::hostname((string)env('TENANCY_CONTROL_HOST',''));
        if(!$tenantId||$hostname===''||$hostname===$control||$hostname==='localhost'||filter_var($hostname,FILTER_VALIDATE_IP))return Response::html('Invalid tenant/domain',422);
        $db=App::get()->service(Database::class);
        if(!$db->row('SELECT id FROM tenants WHERE id=?',[$tenantId]))return Response::html('Unknown tenant',404);
        try{$db->execute('INSERT INTO tenant_domains(tenant_id,hostname) VALUES(?,?)',[$tenantId,$hostname]);}
        catch(Throwable){return Response::html('Domain already assigned or could not be saved',409);}
        return Response::redirect('/admin/tenants');
    }
    public static function status(Request $request):Response {
        $tenantId=filter_var($request->input('tenant_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $status=(string)$request->input('status');
        if(!$tenantId||!in_array($status,['active','suspended'],true))return Response::html('Invalid tenant or status',422);
        $db=App::get()->service(Database::class);
        if($db->execute('UPDATE tenants SET status=? WHERE id=?',[$status,$tenantId])===0)return Response::html('Tenant not changed or not found',404);
        return Response::redirect('/admin/tenants');
    }
    /** Set per-company limits from the central control plane (route-level tenant management permission). */
    public static function mediaQuota(Request $request):Response {
        $tenantId=filter_var($request->input('tenant_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $mib=filter_var($request->input('max_mib'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1048576]]);
        $files=filter_var($request->input('max_files'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]]);
        if(!$tenantId||!$mib||!$files)return Response::html('Invalid media quota limits',422);
        try{App::get()->service(MediaQuota::class)->setTenantLimits((int)$tenantId,(int)$mib*1048576,(int)$files);}
        catch(\RuntimeException $e){return Response::html(esc($e->getMessage()),422);}
        return Response::redirect('/admin/tenants');
    }
    /** Only the central administrator can bootstrap a first company owner. */
    public static function invite(Request $request):Response {
        $tenantId=filter_var($request->input('tenant_id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $role=(string)$request->input('role','owner');
        if(!$tenantId||!TenantPolicy::validRole($role))return Response::html('Invalid tenant or role',422);
        $db=App::get()->service(Database::class);
        $host=$db->row("SELECT d.hostname FROM tenant_domains d JOIN tenants t ON t.id=d.tenant_id WHERE d.tenant_id=? AND t.status='active' ORDER BY d.id LIMIT 1",[$tenantId]);
        if(!$host)return Response::html('Assign a domain to an active tenant before inviting an owner',422);
        try{
            App::get()->service(TenantInvitations::class)->issue($tenantId,(string)$request->input('email'),$role,(int)App::get()->service(\Brightery\Core\Auth::class)->id(),'https://'.$host['hostname']);
        }catch(\RuntimeException $e){return Response::html(esc($e->getMessage()),422);}
        return WorkspaceController::issued();
    }

}
