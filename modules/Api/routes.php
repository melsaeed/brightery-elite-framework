<?php
declare(strict_types=1);
use Brightery\Core\Http\{Router,Response};
use Brightery\Core\{App};
use Brightery\Core\Api\ApiIdentity;
use Brightery\Core\Tenancy\ContextStore;
return static function(Router $r):void {
    $r->get('/api/v1/me',static function():Response {
        $identity=App::get()->service(ApiIdentity::class);
        if(!$identity->allows('profile:read'))return Response::json(['error'=>'forbidden'],403);
        $user=$identity->user();
        return Response::json(['user'=>['id'=>(int)$user['user_id'],'name'=>$user['name'],'email'=>$user['email']]]);
    },name:'api.me');
    $r->get('/api/v1/workspace',static function():Response {
        $identity=App::get()->service(ApiIdentity::class);
        $context=App::get()->service(ContextStore::class)->current();
        if(!$context->isTenant())return Response::json(['error'=>'not_found'],404);
        if(!$identity->allows('workspace:read','workspace.view'))return Response::json(['error'=>'forbidden'],403);
        return Response::json(['tenant'=>['id'=>$context->requireTenantId(),'slug'=>$context->slug], 'role'=>$identity->user()['role']]);
    },name:'api.workspace');
};
