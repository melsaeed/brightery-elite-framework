<?php
declare(strict_types=1);
namespace Brightery\Core;
use RuntimeException;

/** Application templates take precedence over framework theme templates during upgrades. */
class View {
    private array $paths;
    public function __construct(){
        $this->paths=[base_path('app/themes'),base_path('themes')];
    }
    public function addPath(string $directory,bool $prepend=true):void {
        if(!is_dir($directory))throw new RuntimeException('View directory missing');
        $directory=rtrim($directory,'/');
        if($prepend)array_unshift($this->paths,$directory);else $this->paths[]=$directory;
    }
    public function render(string $name,array $data=[]):string {
        if(!preg_match('~^[a-zA-Z0-9_.\/-]+$~D',$name)||str_contains($name,'..')||str_starts_with($name,'/'))throw new RuntimeException('Unsafe view name');
        $relative=str_replace('.','/',$name).'.php';
        $file=null;
        foreach($this->paths as $directory){
            $candidate=$directory.'/'.$relative;
            if(is_file($candidate)){$file=$candidate;break;}
        }
        if($file===null)throw new RuntimeException('View missing: '.$name);
        extract($data,EXTR_SKIP);
        ob_start();try{include $file;return (string)ob_get_clean();}catch(\Throwable $error){ob_end_clean();throw $error;}
    }
}
