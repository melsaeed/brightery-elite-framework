<?php
declare(strict_types=1);
use Brightery\Core\Http\{Router,Response};
return static function(Router $router):void {
    $router->get('/blog',static fn()=>Response::html('<h1>Blog module is active</h1><a href="/">Home</a>'));
};
