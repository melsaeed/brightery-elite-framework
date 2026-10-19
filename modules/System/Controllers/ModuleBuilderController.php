<?php
declare(strict_types=1);
namespace Modules\System\Controllers;
use Brightery\Core\{App,Auth,Security,View};
use Brightery\Core\Developer\{ModuleScaffolder,ModuleBlueprint};
use Brightery\Core\Http\{Request,Response};
use Brightery\Core\I18n\Translator;
use Throwable;

/** Central-only module generator, access-controlled by modules.manage and global CSRF. */
final class ModuleBuilderController {
    private static function shell(string $title,string $template,array $data=[]):Response {
        $app=App::get();$csrf=$app->service(Security::class)->csrf();
        $content=$app->service(View::class)->render('admin/'.$template,$data+['csrf'=>$csrf]);
        return Response::html($app->service(View::class)->render('admin/layout',['title'=>$title,'content'=>$content,'csrf'=>$csrf,'user'=>$app->service(Auth::class)->user(),'translator'=>$app->service(Translator::class)]));
    }
    public static function index():Response {
        return self::shell('Module Builder','module-builder',['initial'=>['name'=>'Products','title'=>'Products','scope'=>'tenant','fields'=>[['name'=>'title','label'=>'Product name','type'=>'text','required'=>true],['name'=>'description','label'=>'Description','type'=>'textarea','required'=>false],['name'=>'price','label'=>'Price','type'=>'decimal','required'=>false]]]]);
    }
    public static function preview(Request $r):Response {
        $input=$r->input('blueprint','');
        if(!is_string($input)||strlen($input)>32768)return Response::html('Invalid blueprint size',422);
        try{
            $decoded=json_decode($input,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($decoded))throw new \InvalidArgumentException('JSON object required');
            $plan=App::get()->service(ModuleScaffolder::class)->plan($decoded);
        }catch(Throwable $e){return Response::html('Blueprint validation failed: '.esc($e->getMessage()).' <a href="/admin/developer">Return</a>',422);}
        return self::shell('Review generated module','module-builder-plan',['plan'=>$plan]);
    }
    public static function apply(Request $r):Response {
        $input=$r->input('blueprint','');$digest=$r->input('digest','');$confirmation=$r->input('confirmation','');
        if(!is_string($input)||strlen($input)>32768||!is_string($digest)||!is_string($confirmation))return Response::html('Invalid request',422);
        try{
            if((string)env('APP_ENV','production')==='production'&&(string)env('MODULE_BUILDER_MAINTENANCE','false')!=='true')throw new \RuntimeException('Module Builder requires MODULE_BUILDER_MAINTENANCE=true on production');
            $decoded=json_decode($input,true,16,JSON_THROW_ON_ERROR);
            if(!is_array($decoded))throw new \InvalidArgumentException('Invalid module blueprint');
            $schema=ModuleBlueprint::normalize($decoded);
            if(!hash_equals('CREATE '.$schema['name'],$confirmation))throw new \RuntimeException('Confirmation text mismatch');
            $plan=App::get()->service(ModuleScaffolder::class)->apply($schema,$digest);
        }catch(Throwable $e){return Response::html('Module not created: '.esc($e->getMessage()).' <a href="/admin/developer">Return</a>',422);}
        return self::shell('Module created','module-builder-success',['schema'=>$plan['schema']]);
    }
}
