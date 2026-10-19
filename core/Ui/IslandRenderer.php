<?php
declare(strict_types=1);
namespace Brightery\Core\Ui;

/** Server-rendered HTML plus progressive JS enhancement. App/modules can register their own island callbacks. */
final class IslandRenderer {
    private array $renderers=[];
    public function register(string $name,callable $callback):void {
        if(!preg_match('/^[a-z][a-z0-9-]*$/D',$name))throw new \InvalidArgumentException('Invalid island name');
        $this->renderers[$name]=$callback;
    }
    public function render(string $name,array $props=[]):string {
        if(!isset($this->renderers[$name]))throw new \RuntimeException('Unknown UI island: '.$name);
        $json=json_encode($props,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
        if(strlen($json)>16000)throw new \InvalidArgumentException('Island props exceed size limit');
        // Renderers are trusted app/module code; all untrusted values must be escaped by the renderer.
        return '<div data-bf-island="'.esc($name).'" data-bf-props="'.esc($json).'">'.($this->renderers[$name])($props).'</div>';
    }
}
