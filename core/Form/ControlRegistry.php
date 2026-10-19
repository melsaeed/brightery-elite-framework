<?php
declare(strict_types=1);
namespace Brightery\Core\Form;
use InvalidArgumentException;
use RuntimeException;
/** Extension point: register/replace named PHP control factories in app/overrides.php or a module. */
class ControlRegistry {
    private array $factories=[];
    public function __construct(){
        $this->register('text',static fn(array $p):string=>Fields::text((string)($p['name']??''),(string)($p['label']??''),(string)($p['value']??''),$p['attrs']??[]));
        $this->register('select',static fn(array $p):string=>Fields::select((string)($p['name']??''),(string)($p['label']??''),$p['options']??[],(string)($p['value']??'')));
        $this->register('file',static fn(array $p):string=>Fields::files((string)($p['name']??''),(string)($p['label']??''),(bool)($p['multiple']??false),$p['accept']??['image/jpeg','image/png','image/webp'],isset($p['library'])?(string)$p['library']:null));
        $this->register('autocomplete',static fn(array $p):string=>Fields::autocompleteSelect((string)($p['name']??''),(string)($p['label']??''),(string)($p['endpoint']??''),(string)($p['placeholder']??'Start typing')));
    }
    public function register(string $name,callable $factory):void {
        if(!preg_match('/^[a-z][a-z0-9._-]{0,63}$/D',$name))throw new InvalidArgumentException('Invalid control name');
        $this->factories[$name]=$factory;
    }
    public function render(string $name,array $props):string {
        if(!isset($this->factories[$name]))throw new RuntimeException('Unknown control');
        $result=($this->factories[$name])($props);
        if(!is_string($result)||strlen($result)>524288)throw new RuntimeException('Control must return bounded HTML');
        // Factories are trusted PHP code and MUST HTML-escape any user-supplied values.
        return $result;
    }
}
