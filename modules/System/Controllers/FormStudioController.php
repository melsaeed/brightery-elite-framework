<?php
declare(strict_types=1);
namespace Modules\System\Controllers;
use Brightery\Core\{App,View,Auth,Security};
use Brightery\Core\Form\FormBlueprint;
use Brightery\Core\Http\{Request,Response};
use Brightery\Core\I18n\Translator;
use Throwable;
final class FormStudioController {
    private static function studio(string $name,?string $error=null):Response {
        $framework=App::get();$forms=$framework->service(FormBlueprint::class);
        try{$schema=$forms->load($name);}catch(Throwable){return Response::html('Invalid form name',422);}
        $schema??=['title'=>'Contact form','fields'=>[['name'=>'email','label'=>'Email address','type'=>'email','required'=>true],['name'=>'message','label'=>'Message','type'=>'textarea','required'=>true]]];
        $view=$framework->service(View::class);
        $content=$view->render('admin/form-studio',['name'=>$name,'forms'=>$forms->listing(),'schema'=>$schema,'preview'=>$forms->render($schema),'error'=>$error,'csrf'=>$framework->service(Security::class)->csrf()]);
        return Response::html($view->render('admin/layout',['title'=>'Form Studio','content'=>$content,'csrf'=>$framework->service(Security::class)->csrf(),'user'=>$framework->service(Auth::class)->user(),'translator'=>$framework->service(Translator::class)]));
    }
    public static function index(Request $r):Response {
        $name=(string)$r->input('name','contact');
        if(!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$name))return Response::html('Invalid form name',422);
        return self::studio($name);
    }
    public static function save(Request $r):Response {
        $name=(string)$r->input('name','');$json=(string)$r->input('schema','');
        if(strlen($json)>65536)return Response::html('Form definition too large',422);
        try{App::get()->service(FormBlueprint::class)->save($name,json_decode($json,true,20,JSON_THROW_ON_ERROR));}
        catch(Throwable $e){return Response::html('Cannot save form: '.esc($e->getMessage()),422);}
        return Response::redirect('/admin/forms?name='.rawurlencode($name));
    }
    public static function export(Request $r):Response {
        $name=(string)$r->input('name','contact');
        try{$schema=App::get()->service(FormBlueprint::class)->load($name);}catch(Throwable){return Response::html('Invalid form',422);}
        if($schema===null)return Response::html('Form not found',404);
        return new Response(json_encode($schema,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),200,['Content-Type'=>'application/json; charset=utf-8','Content-Disposition'=>'attachment; filename="'.$name.'.json"','Cache-Control'=>'private, no-store']);
    }
}
