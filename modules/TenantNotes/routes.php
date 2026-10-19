<?php
declare(strict_types=1);
use Brightery\Core\App;
use Brightery\Core\Http\{Router,Request,Response};
use Brightery\Core\Tenancy\ContextStore;
return static function(Router $router):void {
    // Public, deliberately non-sensitive context check; notes themselves are NEVER exposed anonymously.
    $router->get('/tenant/status',static function(Request $request):Response {
        $context=App::get()->service(ContextStore::class)->current();
        return $context->isTenant()?Response::json(['status'=>'ok','tenant'=>$context->slug]):Response::html('Tenant only',404);
    });
};
