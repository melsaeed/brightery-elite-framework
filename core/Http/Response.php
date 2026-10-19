<?php
declare(strict_types=1);
namespace Brightery\Core\Http;
final class Response {
    public function __construct(public string $body='',public int $status=200,public array $headers=['Content-Type'=>'text/html; charset=UTF-8']){}
    public static function html(string $content,int $status=200): self {return new self($content,$status);}
    public static function json(mixed $data,int $status=200): self {return new self(json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),$status,['Content-Type'=>'application/json; charset=UTF-8','Cache-Control'=>'no-store']);}
    public static function redirect(string $path): self {
        if (!str_starts_with($path,'/') || str_starts_with($path,'//')) $path='/';
        return new self('',303,['Location'=>$path,'Cache-Control'=>'no-store']);
    }
    public function send(bool $head=false): void {
        http_response_code($this->status);
        foreach ($this->headers as $name=>$value) {
            if(!is_string($name)||!preg_match('/^[A-Za-z0-9-]+$/D',$name)||!is_string($value)||strpbrk($value,"\r\n")!==false)throw new \InvalidArgumentException('Invalid response header');
            header($name.': '.$value,true);
        }
        if (!$head && $this->status!==204 && $this->status!==304) echo $this->body;
    }
}
