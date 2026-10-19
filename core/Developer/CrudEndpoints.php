<?php
declare(strict_types=1);
namespace Brightery\Core\Developer;
use Brightery\Core\{App,Auth,Database,Security,View};
use Brightery\Core\Api\ApiIdentity;
use Brightery\Core\Http\{Request,Response};
use Brightery\Core\I18n\Translator;
use Brightery\Core\Tenancy\{ContextStore,TenantAccess};
use InvalidArgumentException;

/** Reusable endpoints; generated module classes remain editable by the application. */
class CrudEndpoints {
    public function __construct(private readonly CrudRepository $repository){}
    private function schema():array{return $this->repository->schema();}
    private function prefix():string { $s=$this->schema();return ($s['scope']==='tenant'?'/workspace/':'/admin/').$s['slug']; }
    private function scopeMatches():bool {
        $tenant=App::get()->service(ContextStore::class)->current()->isTenant();
        return $tenant===($this->schema()['scope']==='tenant');
    }
    private function canWrite():bool {
        if($this->schema()['scope']==='tenant')return App::get()->service(TenantAccess::class)->allows('content.write');
        return App::get()->service(Auth::class)->can('module.'.$this->schema()['slug'].'.write');
    }
    private function id(Request $r):?int {
        $raw=$r->input('id');
        $id=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        return $id===false||$id===null?null:(int)$id;
    }
    private function payload(Request $r,bool $api=false):array {
        $source=$api?$r->jsonBody():$r->post;
        if(!$api)unset($source['_csrf'],$source['id']);
        return $source;
    }
    private function apiAllowed(bool $write):bool {
        if(!$this->scopeMatches())return false;
        $identity=App::get()->service(ApiIdentity::class);
        if(!$identity->allows($write?'modules:write':'modules:read',$this->schema()['scope']==='tenant'?($write?'content.write':'content.read'):null))return false;
        if($this->schema()['scope']==='central'){
            $userId=(int)($identity->user()['user_id']??0);
            if($userId<1)return false;
            $code='module.'.$this->schema()['slug'].'.'.($write?'write':'read');
            return (bool)App::get()->service(Database::class)->row("SELECT 1 FROM user_groups ug JOIN group_permissions gp ON gp.group_id=ug.group_id JOIN permissions p ON p.id=gp.permission_id WHERE ug.user_id=? AND (p.code=? OR p.code='*') LIMIT 1",[$userId,$code]);
        }
        return true;
    }
    /** A source module's write permission does not imply access to unrelated central modules. */
    private function referencedReadable(array $payload,bool $api=false):bool {
        if($this->schema()['scope']==='tenant')return true;
        foreach($this->schema()['fields'] as $field){
            if(!in_array($field['type'],['relation','many_to_many'],true)||!array_key_exists($field['name'],$payload))continue;
            $slug=strtolower(preg_replace('/([a-z0-9])([A-Z])/','$1_$2',$field['references']));
            $code='module.'.str_replace('_','-',$slug).'.read';
            if(!$api){if(!App::get()->service(Auth::class)->can($code))return false;continue;}
            $userId=(int)(App::get()->service(ApiIdentity::class)->user()['user_id']??0);
            if($userId<1||!App::get()->service(Database::class)->row("SELECT 1 FROM user_groups ug JOIN group_permissions gp ON gp.group_id=ug.group_id JOIN permissions p ON p.id=gp.permission_id WHERE ug.user_id=? AND (p.code=? OR p.code='*') LIMIT 1",[$userId,$code]))return false;
        }
        return true;
    }
    private function deniedApi(bool $write):?Response {
        if(!$this->scopeMatches())return Response::json(['error'=>'not_found'],404);
        if(!$this->apiAllowed($write))return Response::json(['error'=>'forbidden'],403);
        return null;
    }
    private function render(array $listing,?array $editing=null,?string $error=null,array $query=[]):Response {
        $s=$this->schema();$app=App::get();$csrf=$app->service(Security::class)->csrf();
        $relations=[];
        foreach($s['fields'] as $field)if(in_array($field['type'],['relation','many_to_many'],true)){
            $selected=isset($editing[$field['name']])?(int)$editing[$field['name']]:null;
            $canViewTarget=true;
            if($s['scope']==='central'){
                $targetSlug=strtolower(preg_replace('/([a-z0-9])([A-Z])/','$1_$2',$field['references']));
                $canViewTarget=$app->service(Auth::class)->can('module.'.str_replace('_','-',$targetSlug).'.read');
            }
            $options=$field['type']==='many_to_many'
                ?($editing!==null&&$canViewTarget?$this->repository->relatedChoices($field['name'],(int)$editing['id']):[])
                :($selected!==null&&$canViewTarget?$this->repository->searchRelation($field['name'],'',$selected,1):[]);
            $relations[$field['name']]=$field['type']==='many_to_many'?$options:array_values(array_filter($options,static fn($option)=>$selected!==null&&(int)$option['id']===$selected));
        }
        $content=$app->service(View::class)->render('admin/generated-crud',['schema'=>$s,'records'=>$listing['data'],'listing'=>$listing,'relations'=>$relations,'query'=>$query,'editing'=>$editing,'error'=>$error,'csrf'=>$csrf,'prefix'=>$this->prefix(),'canWrite'=>$this->canWrite()]);
        if($s['scope']==='tenant')return Response::html($app->service(View::class)->render('workspace/layout',['title'=>$s['title'],'content'=>$content,'csrf'=>$csrf,'tenant'=>$app->service(ContextStore::class)->current(),'member'=>$app->service(TenantAccess::class)->membership()]));
        return Response::html($app->service(View::class)->render('admin/layout',['title'=>$s['title'],'content'=>$content,'csrf'=>$csrf,'user'=>$app->service(Auth::class)->user(),'translator'=>$app->service(Translator::class)]));
    }
    private function listQuery(Request $r):array {
        $query=array_intersect_key($r->query,array_flip(['page','per_page','search','filter','value','sort','direction','view']));
        if(($query['filter']??null)===''){unset($query['filter'],$query['value']);}
        return $query;
    }
    public function index(Request $r):Response {
        if(!$this->scopeMatches())return Response::html('Not found',404);
        $editing=null;$id=$this->id($r);
        if(($r->query['view']??'active')==='trash'&&!$this->canWrite())return Response::html('Forbidden',403);
        if($id!==null&&($r->query['view']??'active')==='trash')return Response::html('Trashed records cannot be edited',422);
        if($id!==null){$editing=$this->repository->find($id);if(!$editing)return Response::html('Record not found',404);}
        try{$query=$this->listQuery($r);return $this->render($this->repository->page($query),$editing,null,$query);}
        catch(InvalidArgumentException $e){return Response::html('Invalid listing parameters',422);}
    }
    /** Guarded AJAX lookup; a central user must also have access to the referenced module. */
    public function relationLookup(Request $r):Response {
        if(!$this->scopeMatches())return Response::json(['error'=>'not_found'],404);
        $field=$r->query['field']??null;$query=$r->query['q']??'';$selected=$r->query['selected']??null;
        if(!is_string($field)||!is_string($query)||strlen($query)>80||($selected!==null&&!is_string($selected)))return Response::json(['error'=>'invalid_query'],422);
        $relation=null;
        foreach($this->schema()['fields'] as $f)if(in_array($f['type'],['relation','many_to_many'],true)&&$f['name']===$field)$relation=$f;
        if($relation===null)return Response::json(['error'=>'invalid_field'],422);
        if($this->schema()['scope']==='central'){
            $slug=strtolower(preg_replace('/([a-z0-9])([A-Z])/','$1_$2',$relation['references']));
            if(!App::get()->service(Auth::class)->can('module.'.str_replace('_','-',$slug).'.read'))return Response::json(['error'=>'forbidden'],403);
        }
        $id=null;
        if($selected!==null){$id=filter_var($selected,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($id===false)return Response::json(['error'=>'invalid_query'],422);}
        try{return Response::json(['items'=>$this->repository->searchRelation($field,$query,$id,20)]);}
        catch(InvalidArgumentException){return Response::json(['error'=>'invalid_query'],422);}
    }
    /** Download at most 100 filtered records; cells are quoted and formula-neutralized. */
    public function exportCsv(Request $r):Response {
        if(!$this->scopeMatches())return Response::html('Not found',404);
        try{$query=$this->listQuery($r);unset($query['view']);$rows=$this->repository->csvRows($query);}
        catch(InvalidArgumentException){return Response::html('Invalid export filters',422);}
        $handle=fopen('php://temp','w+');if($handle===false)return Response::html('Export unavailable',500);
        foreach($rows as $row)fputcsv($handle,$row,',','"','');
        rewind($handle);$csv=stream_get_contents($handle);fclose($handle);
        return new Response($csv,200,['Content-Type'=>'text/csv; charset=UTF-8','Content-Disposition'=>'attachment; filename="'.$this->schema()['slug'].'-export.csv"','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    /** A bounded, atomic restore action, shared by all soft-delete enabled modules. */
    public function bulkRestore(Request $r):Response {
        if(!$this->scopeMatches())return Response::html('Not found',404);
        if(!$this->repository->softDeleteEnabled()||$r->input('confirm')!=='RESTORE')return Response::html('Bulk restore confirmation required',422);
        $ids=$r->post['ids']??null;
        try{if(!is_array($ids))throw new InvalidArgumentException('Select records');$this->repository->bulkRestore($ids);}
        catch(InvalidArgumentException){return Response::html('Restore selection unavailable',422);}
        return Response::redirect($this->prefix().'?view=trash');
    }
    public function bulkRemove(Request $r):Response {
        if(!$this->scopeMatches())return Response::html('Not found',404);
        if($r->input('confirm')!=='DELETE')return Response::html('Bulk deletion requires explicit confirmation',422);
        $ids=$r->post['ids']??null;
        if(!is_array($ids))return Response::html('Select records to delete',422);
        try{$this->repository->bulkDelete($ids);}
        catch(InvalidArgumentException){return Response::html('One or more records are invalid or inaccessible; no records were deleted',422);}
        return Response::redirect($this->prefix());
    }
    public function restore(Request $r):Response {
        if(!$this->scopeMatches())return Response::html('Not found',404);
        $id=$this->id($r);
        if($id===null||!$this->repository->softDeleteEnabled())return Response::html('Restore unavailable',422);
        return $this->repository->restore($id)>0?Response::redirect($this->prefix().'?view=trash'):Response::html('Record not found in trash',404);
    }
    public function save(Request $r):Response {
        if(!$this->scopeMatches())return Response::html('Not found',404);
        $id=$this->id($r);
        if(!$this->referencedReadable($this->payload($r)))return Response::html('Referenced module permission required',403);
        try{
            if($id!==null){if(!$this->repository->find($id))return Response::html('Record not found',404);$this->repository->update($id,$this->payload($r));}
            else $this->repository->create($this->payload($r));
        }catch(InvalidArgumentException $e){return $this->render($this->repository->page(),null,$e->getMessage());}
        return Response::redirect($this->prefix());
    }
    public function remove(Request $r):Response {
        if(!$this->scopeMatches())return Response::html('Not found',404);
        $id=$this->id($r);if($id===null)return Response::html('Invalid record',422);
        $this->repository->delete($id);return Response::redirect($this->prefix());
    }
    public function apiList(Request $r):Response {
        if($deny=$this->deniedApi(false))return $deny;
        try{$q=$this->listQuery($r);unset($q['view']);return Response::json($this->repository->page($q));}
        catch(InvalidArgumentException){return Response::json(['error'=>'invalid_query'],422);}
    }
    public function apiCreate(Request $r):Response {
        if($deny=$this->deniedApi(true))return $deny;
        if(!$this->referencedReadable($this->payload($r,true),true))return Response::json(['error'=>'forbidden_reference'],403);
        try{$id=$this->repository->create($this->payload($r,true));}
        catch(InvalidArgumentException $e){return Response::json(['error'=>'validation','message'=>$e->getMessage()],422);}
        return Response::json(['id'=>$id,'data'=>$this->repository->find($id)],201);
    }
    public function apiUpdate(Request $r,array $params):Response {
        if($deny=$this->deniedApi(true))return $deny;
        $id=filter_var($params['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id)return Response::json(['error'=>'invalid_id'],422);
        if(!$this->repository->find((int)$id))return Response::json(['error'=>'not_found'],404);
        if(!$this->referencedReadable($this->payload($r,true),true))return Response::json(['error'=>'forbidden_reference'],403);
        try{$this->repository->update((int)$id,$this->payload($r,true),true);}catch(InvalidArgumentException $e){return Response::json(['error'=>'validation','message'=>$e->getMessage()],422);}
        return Response::json(['data'=>$this->repository->find((int)$id)]);
    }
    public function apiDelete(Request $r,array $params):Response {
        if($deny=$this->deniedApi(true))return $deny;
        $id=filter_var($params['id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if(!$id)return Response::json(['error'=>'invalid_id'],422);
        return $this->repository->delete((int)$id)>0?Response::json(['deleted'=>true]):Response::json(['error'=>'not_found'],404);
    }
}
