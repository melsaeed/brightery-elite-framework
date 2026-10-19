<?php
declare(strict_types=1);
namespace Brightery\Core\Ui;

final class CoreIslands {
    public static function install(IslandRenderer $registry):void {
        $registry->register('tags',static function(array $props):string {
            $name=(string)($props['name']??'tags');$label=(string)($props['label']??'Tags');
            if(!preg_match('/^[a-z][a-z0-9_\[\]]*$/D',$name))throw new \InvalidArgumentException('Unsafe field name');
            $tags=array_values(array_filter($props['values']??[],static fn($v)=>is_string($v)&&strlen($v)<=80));
            $initial=implode(',',array_slice($tags,0,25));
            return '<label class="bf-field bf-tags-field"><span>'.esc($label).'</span><div class="bf-tags" data-bf-tags-list></div><input type="text" maxlength="80" data-bf-tags-input placeholder="Add tag and press Enter" aria-label="'.esc($label).'"><input type="hidden" name="'.esc($name).'" data-bf-tags-value value="'.esc($initial).'"></label>';
        });
    }
}
