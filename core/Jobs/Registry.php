<?php
declare(strict_types=1);
namespace Brightery\Core\Jobs;
use RuntimeException;
/** Handlers are trusted code registered at boot: never deserialize PHP objects/callables from queue payloads. */
final class Registry {
    private array $handlers=[];
    public function register(string $name,callable $handler):void {
        self::validateName($name);
        if(isset($this->handlers[$name]))throw new RuntimeException('Duplicate job handler: '.$name);
        $this->handlers[$name]=$handler;
    }
    public function has(string $name):bool {return isset($this->handlers[$name]);}
    public function run(string $name,array $payload):void {
        if(!isset($this->handlers[$name]))throw new RuntimeException('Job handler unavailable: '.$name);
        ($this->handlers[$name])($payload);
    }
    public static function validateName(string $name):void {
        if(!preg_match('/^[a-z][a-z0-9_.-]{1,149}$/D',$name))throw new RuntimeException('Invalid job identifier');
    }
}
