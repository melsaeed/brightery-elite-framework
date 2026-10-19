<?php
declare(strict_types=1);
namespace Modules\System\Controllers;
use Brightery\Core\{App,Security,RateBuckets};
use Brightery\Core\Http\{Request,Response};
use Brightery\Core\Media\{PrivateMedia,ImageVariants,MediaQuota};
use Brightery\Core\Tenancy\ContextStore;
use Brightery\Core\Form\Fields;
final class PrivateMediaController {
    private static function svc(string $id):object{return App::get()->service($id);}
    private static function base():string{return self::svc(ContextStore::class)->current()->isTenant()?'/workspace/media':'/admin/media/private';}
    public static function index():Response {
        $media=self::svc(PrivateMedia::class);$csrf=esc(self::svc(Security::class)->csrf());$base=self::base();
        $quota=self::svc(MediaQuota::class)->snapshot();
        $quotaText='Storage used: '.number_format($quota['used_bytes']/1048576,2).' / '.number_format($quota['max_bytes']/1048576,2).' MiB · '.number_format($quota['original_count']).' / '.number_format($quota['max_files']).' files';
        $items='';foreach($media->list() as $file){
            $id=(int)$file['id'];$url=esc($base.'/file/'.$id);
            $form='';
            if(str_starts_with((string)$file['mime'],'image/'))$form='<form method="post" action="'.esc($base.'/transform').'" class="bf-media-transform"><input type="hidden" name="_csrf" value="'.$csrf.'"><input type="hidden" name="id" value="'.$id.'"><label>Width <input type="number" name="width" min="16" max="2400" value="640" required></label><label>Height <input type="number" name="height" min="16" max="2400" value="640" required></label><label>Mode <select name="mode"><option value="cover">Crop to fill</option><option value="contain">Fit on white</option></select></label><button type="submit" class="btn">Create variant</button></form>';
            $variants=self::svc(ImageVariants::class)->list($id);$links='';foreach($variants as $v)$links.='<a href="'.esc($base.'/variant/'.(int)$v['id']).'">'.(int)$v['width'].'×'.(int)$v['height'].' JPEG</a> ';
            $items.='<li class="bf-private-row"><div><label><input type="checkbox" form="bf-bulk-delete" name="ids[]" value="'.$id.'"> <a href="'.$url.'">'.esc($file['original_name']).'</a></label> <small>'.esc($file['mime']).' · '.number_format(((int)$file['bytes'])/1024,1).' KB</small></div>'.$form.'<div class="bf-media-variants">'.$links.'</div></li>';
        }
        $assets=self::svc(\Brightery\Core\Ui\AssetManager::class);
        $fileControl=Fields::files('file','Upload private media',false,['image/jpeg','image/png','image/webp','application/pdf']);
        $body='<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Private media · Brightery</title><link rel="stylesheet" href="'.esc($assets->url('css/app.min.css')).'"><script src="'.esc($assets->url('js/app.js')).'" defer></script></head><body class="bf-admin"><main class="main-content"><h1>Private media</h1><p class="bf-muted">'.esc($quotaText).'</p><p>Files and image variants are tenant-scoped and stored outside the web root. JPEG, PNG, WebP and PDF up to 10 MB; images require GD for server-side transforms.</p><form enctype="multipart/form-data" method="post" action="'.esc($base.'/upload').'"><input type="hidden" name="_csrf" value="'.$csrf.'">'.$fileControl.'<button class="btn btn-primary">Upload file</button></form><hr><h2>Stored files</h2><form id="bf-bulk-delete" method="post" action="'.esc($base.'/delete').'"><input type="hidden" name="_csrf" value="'.$csrf.'"><button class="btn btn-secondary" type="submit" data-confirm-delete>Delete selected files</button></form><ul class="bf-private-list">'.$items.'</ul><p><a href="'.(str_starts_with($base,'/workspace')?'/workspace':'/admin').'">← Back to dashboard</a></p></main></body></html>';
        return Response::html($body);
    }
    public static function upload(Request $request):Response {
        $file=$request->files['file']??null;
        if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_string($file['name']??null))return Response::html('Invalid upload',422);
        $ctx=self::svc(ContextStore::class)->current();$actor=(int)self::svc(Security::class)->userId();
        if(!self::svc(RateBuckets::class)->hit($ctx->isTenant()?$ctx->requireTenantId():null,'media.upload',(string)$actor,120,3600))return Response::html('Upload rate limit reached',429);
        try{self::svc(PrivateMedia::class)->storeUploaded($file['tmp_name'],$file['name'],$actor);}
        catch(\RuntimeException $e){return Response::html(esc($e->getMessage()),422);}
        return Response::redirect(self::base());
    }
    public static function library():Response {
        $records=self::svc(PrivateMedia::class)->list();
        return Response::json(array_map(static fn(array $r):array=>['id'=>(int)$r['id'],'original_name'=>$r['original_name'],'mime'=>$r['mime'],'bytes'=>(int)$r['bytes']],$records));
    }
    public static function transform(Request $r):Response {
        $id=filter_var($r->input('id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $w=filter_var($r->input('width'),FILTER_VALIDATE_INT,['options'=>['min_range'=>16,'max_range'=>2400]]);
        $h=filter_var($r->input('height'),FILTER_VALIDATE_INT,['options'=>['min_range'=>16,'max_range'=>2400]]);
        if(!$id||!$w||!$h)return Response::html('Invalid image transform parameters',422);
        $context=self::svc(ContextStore::class)->current();
        $tenant=$context->isTenant()?$context->requireTenantId():null;
        if(!self::svc(RateBuckets::class)->hit($tenant,'media.transform',(string)self::svc(Security::class)->userId(),60,3600))return Response::html('Transform rate limit reached',429);
        try{$variant=self::svc(ImageVariants::class)->create($id,$w,$h,(string)$r->input('mode','cover'));}
        catch(\RuntimeException $e){return Response::html(esc($e->getMessage()),extension_loaded('gd')?422:503);}
        return Response::redirect(self::base().'/variant/'.$variant);
    }
    public static function delete(Request $r):Response {
        $values=$r->input('ids',[]);
        if(!is_array($values)||count($values)>50)return Response::html('Invalid selection',422);
        $ids=[];foreach($values as $v){$n=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$n)return Response::html('Invalid selection',422);$ids[]=$n;}
        try{self::svc(PrivateMedia::class)->bulkDelete($ids);}catch(\RuntimeException){return Response::html('Invalid selection',422);}
        return Response::redirect(self::base());
    }
    private static function sendFile(?array $file):Response {
        if($file===null)return Response::html('Not found',404);
        $name=str_replace(['"','\\',"\r","\n"],'_',basename($file['name']));
        return new Response($file['bytes'],200,['Content-Type'=>$file['mime'],'Content-Disposition'=>'attachment; filename="'.substr($name,0,180).'"','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    public static function file(Request $request,array $params):Response {return self::sendFile(self::svc(PrivateMedia::class)->read((int)($params['id']??0)));}
    public static function variant(Request $request,array $params):Response {return self::sendFile(self::svc(ImageVariants::class)->read((int)($params['id']??0)));}
}
