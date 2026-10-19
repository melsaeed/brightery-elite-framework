<?php
declare(strict_types=1);
namespace Modules\System\Controllers;
use Brightery\Core\{App,Auth,Security,View};
use Brightery\Core\Developer\ModuleDiagnostics;
use Brightery\Core\Http\{Request,Response};
use Brightery\Core\I18n\Translator;
use Brightery\Core\Modules\ModuleManager;
final class ModuleDiagnosticsController {
    public static function index(Request $r):Response {
        $app=App::get();$catalog=$app->service(ModuleManager::class)->available();
        $name=$r->query['module']??null;$report=null;
        if($name!==null){
            if(!is_string($name)||!array_key_exists($name,$catalog))return Response::html('Unknown module',404);
            $report=(new ModuleDiagnostics(base_path('modules')))->inspect($name);
        }
        $csrf=$app->service(Security::class)->csrf();
        $content=$app->service(View::class)->render('admin/module-diagnostics',['catalog'=>$catalog,'report'=>$report,'selected'=>$name]);
        return Response::html($app->service(View::class)->render('admin/layout',['title'=>'Module Diagnostics','content'=>$content,'csrf'=>$csrf,'user'=>$app->service(Auth::class)->user(),'translator'=>$app->service(Translator::class)]));
    }
}
