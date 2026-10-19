<?php
declare(strict_types=1);
namespace Brightery\Core\Http;
final class Request {
    public function __construct(public readonly string $method, public readonly string $path, public readonly array $query=[],public readonly array $post=[],public readonly array $files=[],public readonly array $server=[]){}
    public static function capture(): self {
        $path=parse_url($_SERVER['REQUEST_URI'] ?? '/',PHP_URL_PATH) ?: '/';
        $path=rawurldecode($path);
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),$path,$_GET,$_POST,$_FILES,$_SERVER);
    }
    public function input(string $name, mixed $default=null): mixed {return $this->post[$name] ?? $this->query[$name] ?? $default;}
    /** JSON request payload, bounded and object-only. Used by generated REST controllers. */
    public function jsonBody(): array {
        if($this->post!==[])return $this->post;
        $type=strtolower((string)($this->server['CONTENT_TYPE']??''));
        if(!str_starts_with($type,'application/json'))throw new \InvalidArgumentException('Content-Type must be application/json');
        $body=file_get_contents('php://input',false,null,0,65537);
        if($body===false||strlen($body)>65536||trim($body)==='')throw new \InvalidArgumentException('Invalid JSON size');
        try{$result=json_decode($body,true,16,JSON_THROW_ON_ERROR);}
        catch(\JsonException){throw new \InvalidArgumentException('Invalid JSON');}
        if(!is_array($result)||array_is_list($result))throw new \InvalidArgumentException('JSON object required');
        return $result;
    }
    public function ip(): string {return (string)($this->server['REMOTE_ADDR'] ?? '');}
    public function header(string $name): string {return (string)($this->server['HTTP_'.strtoupper(str_replace('-', '_',$name))] ?? '');}
}
