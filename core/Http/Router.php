<?php
declare(strict_types=1);
namespace Brightery\Core\Http;
use RuntimeException;

/** Minimal named, grouped MVC router; middleware order: global group => route => handler. */
final class Router {
    private array $routes=[];
    private array $named=[];
    private array $groups=[];
    public function add(string $method,string $path,callable $handler,array $middleware=[],?string $name=null):void {
        if(!str_starts_with($path,'/')||str_starts_with($path,'//'))throw new RuntimeException('Routes must begin with /');
        $prefix='';$shared=[];
        foreach($this->groups as $group){$prefix.=$group['prefix'];$shared=array_merge($shared,$group['middleware']);}
        $fullPath=$prefix==='/'?$path:rtrim($prefix,'/').$path;
        if($fullPath==='')$fullPath='/';
        $route=['method'=>strtoupper($method),'path'=>$fullPath,'handler'=>$handler,'middlewares'=>[...$shared,...$middleware],'name'=>$name];
        if($name!==null){
            if(!preg_match('/^[a-z][a-z0-9_.-]*$/D',$name)||isset($this->named[$name]))throw new RuntimeException('Duplicate or invalid route name');
            $this->named[$name]=$fullPath;
        }
        $this->routes[]=$route;
    }
    public function get(string $path,callable $handler,array $middleware=[],?string $name=null):void {$this->add('GET',$path,$handler,$middleware,$name);}
    public function post(string $path,callable $handler,array $middleware=[],?string $name=null):void {$this->add('POST',$path,$handler,$middleware,$name);}
    public function group(string $prefix,callable $registrar,array $middleware=[]):void {
        if(!str_starts_with($prefix,'/')||str_contains($prefix,'..')||str_contains($prefix,'//'))throw new RuntimeException('Invalid route group prefix');
        $this->groups[]=['prefix'=>rtrim($prefix,'/'),'middleware'=>$middleware];
        try{$registrar($this);}finally{array_pop($this->groups);}
    }
    public function url(string $name,array $parameters=[]):string {
        if(!isset($this->named[$name]))throw new RuntimeException('Unknown route name: '.$name);
        return preg_replace_callback('/\{([A-Za-z][A-Za-z0-9_]*)\}/',static function(array $match)use($parameters):string {
            if(!array_key_exists($match[1],$parameters)||!is_scalar($parameters[$match[1]]))throw new RuntimeException('Missing route parameter '.$match[1]);
            $value=(string)$parameters[$match[1]];
            if($value===''||str_contains($value,'/')||str_contains($value,'\\'))throw new RuntimeException('Unsafe route parameter');
            return rawurlencode($value);
        },$this->named[$name]);
    }
    public function routes():array {return $this->routes;}
    public function dispatch(Request $request):Response {
        if(str_contains($request->path,"\0")||str_contains($request->path,'\\')||strlen($request->path)>4096)return Response::html('Bad request',400);
        $methodMatched=false;
        foreach($this->routes as $route){
            $pattern=preg_replace('/\\\\\{([A-Za-z][A-Za-z0-9_]*)\\\\\}/','(?P<$1>[^/]+)',preg_quote($route['path'],'~'));
            if(!preg_match('~^'.$pattern.'/?$~D',$request->path,$matches))continue;
            if($route['method']!==$request->method&&!($request->method==='HEAD'&&$route['method']==='GET')){$methodMatched=true;continue;}
            $params=array_filter($matches,'is_string',ARRAY_FILTER_USE_KEY);
            $runner=static fn():Response=>($route['handler'])($request,$params);
            foreach(array_reverse($route['middlewares']) as $middleware){$next=$runner;$runner=static fn():Response=>$middleware($request,$next);}
            return $runner();
        }
        return Response::html($methodMatched?'Method not allowed':'Not found',$methodMatched?405:404);
    }
}
