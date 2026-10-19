<?php
declare(strict_types=1);
namespace Modules\System\Controllers;
use Brightery\Core\{App,Auth,Security,View};
use Brightery\Core\Developer\{ModuleBlueprint,ModuleEvolver};
use Brightery\Core\Http\{Request,Response};
use Brightery\Core\I18n\Translator;
use Brightery\Core\Modules\ModuleManager;
use Throwable;

/** Protected developer studio; uses the same append-only validator and stale-review digest as the CLI. */
final class ModuleEvolutionController {
    private static function render(string $title,string $view,array $variables=[]):Response {
        $app=App::get();$csrf=$app->service(Security::class)->csrf();
        $content=$app->service(View::class)->render('admin/'.$view,$variables+['csrf'=>$csrf]);
        return Response::html($app->service(View::class)->render('admin/layout',['title'=>$title,'content'=>$content,'csrf'=>$csrf,'user'=>$app->service(Auth::class)->user(),'translator'=>$app->service(Translator::class)]));
    }
    private static function evolver():ModuleEvolver{return new ModuleEvolver(base_path('modules'));}
    private static function definitions():array {
        $modules=App::get()->service(ModuleManager::class);$available=[];
        foreach($modules->available() as $name=>$details){
            $file=base_path('modules/'.$name.'/blueprint.json');
            if(is_file($file)&&!is_link($file)&&!is_link(dirname($file))){
                try{$available[$name]=['schema'=>ModuleBlueprint::fromFile($file),'enabled'=>$modules->enabled($name)];}
                catch(Throwable){/* Skip corrupted module blueprints in selector. */}
            }
        }
        return $available;
    }
    public static function index(Request $request):Response {
        $available=self::definitions();$selected=$request->query['module']??null;
        if($selected!==null&&!is_string($selected))return Response::html('Invalid module',422);
        if($selected===null)$selected=array_key_first($available);
        if($selected!==null&&!isset($available[$selected]))return Response::html('Module not found',404);
        return self::render('Evolve module','module-evolve',['available'=>$available,'selected'=>$selected,'initial'=>$selected!==null?$available[$selected]['schema']:null]);
    }
    private static function decode(Request $request):array {
        $raw=$request->post['blueprint']??null;
        if(!is_string($raw)||strlen($raw)>32768)throw new \InvalidArgumentException('Invalid blueprint size');
        $json=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($json)||array_is_list($json))throw new \InvalidArgumentException('Expected JSON object');
        return $json;
    }
    public static function preview(Request $request):Response {
        $name=$request->post['module']??null;
        if(!is_string($name))return Response::html('Invalid module',422);
        try{$plan=self::evolver()->plan($name,self::decode($request));}
        catch(Throwable $e){return Response::html('Evolution preview failed: '.esc($e->getMessage()).' <a href="/admin/developer/evolve">Back</a>',422);}
        $enabled=App::get()->service(ModuleManager::class)->enabled($name);
        return self::render('Review schema evolution','module-evolve-plan',['plan'=>$plan,'enabled'=>$enabled]);
    }
    public static function apply(Request $request):Response {
        $name=$request->post['module']??null;$digest=$request->post['digest']??null;$confirmation=$request->post['confirmation']??null;
        if(!is_string($name)||!is_string($digest)||!is_string($confirmation))return Response::html('Invalid request',422);
        try{
            if(!hash_equals('EVOLVE '.$name,$confirmation))throw new \RuntimeException('Confirmation phrase mismatch');
            if((string)env('APP_ENV','production')==='production'&&((string)env('MAINTENANCE_MODE','false')!=='true'||(string)env('MODULE_BUILDER_MAINTENANCE','false')!=='true'))throw new \RuntimeException('Production maintenance gates are required');
            if(App::get()->service(ModuleManager::class)->enabled($name))throw new \RuntimeException('Disable module before changing schema');
            $plan=self::evolver()->apply($name,self::decode($request),$digest);
        }catch(Throwable $e){return Response::html('Schema evolution rejected: '.esc($e->getMessage()).' <a href="/admin/developer/evolve">Back</a>',422);}
        return self::render('Schema evolution created','module-evolve-complete',['plan'=>$plan]);
    }
}
