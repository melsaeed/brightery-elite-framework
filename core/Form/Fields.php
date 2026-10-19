<?php
declare(strict_types=1);
namespace Brightery\Core\Form;
final class Fields {
    public static function text(string $name,string $label,string $value='',array $attrs=[]): string {
        return '<label class="bf-field"><span>'.esc($label).'</span><input type="text" name="'.esc($name).'" value="'.esc($value).'"'.self::attrs($attrs).'></label>';
    }
    public static function select(string $name,string $label,array $options,string $selected=''): string {
        $o='';foreach($options as $value=>$text)$o.='<option value="'.esc($value).'"'.((string)$selected===(string)$value?' selected':'').'>'.esc($text).'</option>';
        return '<label class="bf-field"><span>'.esc($label).'</span><select name="'.esc($name).'">'.$o.'</select></label>';
    }
    public static function files(string $name,string $label,bool $multiple=true,array $accept=['image/jpeg','image/png','image/webp'],?string $libraryUrl=null): string {
        $id='file-'.bin2hex(random_bytes(5));$a=implode(',',array_map('esc',$accept));
        $picker='';
        if($libraryUrl!==null){
            if(!str_starts_with($libraryUrl,'/')||str_starts_with($libraryUrl,'//')||str_contains($libraryUrl,'\\'))throw new \InvalidArgumentException('File library endpoint must be same-origin');
            $picker='<button type="button" class="btn btn-secondary" data-bf-open-library data-endpoint="'.esc($libraryUrl).'">Select existing</button><input type="hidden" name="'.esc($name).'_existing_id" data-bf-existing-id><dialog class="bf-library-dialog"><header><strong>Private media library</strong> <button type="button" data-bf-close-library>Close</button></header><div data-bf-library-files></div></dialog>';
        }
        return '<div class="bf-field bf-dropzone" data-file-control><label for="'.$id.'">'.esc($label).'</label><div class="bf-drop-area" tabindex="0" role="button">Drop files here or click to browse</div><input id="'.$id.'" name="'.esc($name).($multiple?'[]':'').'" type="file" accept="'.$a.'"'.($multiple?' multiple':'').'><div class="bf-file-list" aria-live="polite"></div>'.$picker.'<small>Preview, reorder, remove or crop JPEG/PNG/WebP images before upload. Server validates every file independently.</small></div>';
    }
    public static function autocomplete(string $name,string $label,string $endpoint,string $placeholder='Start typing'):string{
        // Endpoints restricted to same-origin absolute paths.
        if(!str_starts_with($endpoint,'/')||str_starts_with($endpoint,'//'))throw new \InvalidArgumentException('Endpoint must be same origin');
        $id='auto-'.bin2hex(random_bytes(5));
        return '<label class="bf-field bf-autocomplete" data-autocomplete="'.esc($endpoint).'"><span>'.esc($label).'</span><input id="'.$id.'" name="'.esc($name).'" placeholder="'.esc($placeholder).'" autocomplete="off"><div class="bf-suggestions" role="listbox"></div></label>';
    }
    /** Hidden stable ID and display label are separate; AJAX results must be [{id,label}]. */
    public static function autocompleteSelect(string $name,string $label,string $endpoint,string $placeholder='Start typing'):string {
        if(!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,79}$/D',$name))throw new \InvalidArgumentException('Unsafe input name');
        if(!str_starts_with($endpoint,'/')||str_starts_with($endpoint,'//')||str_contains($endpoint,'\\'))throw new \InvalidArgumentException('Endpoint must be same origin');
        $id='bf-select-'.bin2hex(random_bytes(5));
        return '<label class="bf-field bf-autocomplete" data-autocomplete="'.esc($endpoint).'" data-autocomplete-id-mode="1"><span>'.esc($label).'</span><input id="'.$id.'" type="search" placeholder="'.esc($placeholder).'" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false"><input type="hidden" name="'.esc($name).'" value="" data-autocomplete-value><div class="bf-suggestions" role="listbox"></div></label>';
    }
    private static function attrs(array $attrs): string {$out='';foreach($attrs as $key=>$value){if(!preg_match('/^(placeholder|required|readonly|maxlength|minlength|autocomplete|inputmode)$/',$key))continue;$out.=' '.esc($key).'="'.esc($value).'"';}return $out;}
}
