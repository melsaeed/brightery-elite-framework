<?php
declare(strict_types=1);
// Only public/ should be the web-server document root.
if (PHP_SAPI === 'cli-server') {
    $path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/';
    if($path !== '/' && is_file(__DIR__.$path))return false;
}
$kernel=require dirname(__DIR__).'/bootstrap/app.php';
$request=\Brightery\Core\Http\Request::capture();
$kernel->handle($request)->send($request->method==='HEAD');
