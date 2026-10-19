<?php
declare(strict_types=1);
namespace Brightery\Core\Mail;
use RuntimeException;
/** Plain-text, locale-aware, override-friendly templates (no PHP evaluation). */
class TemplateCatalog {
    public function render(string $key,array $values=[],?string $locale=null):array {
        if(!preg_match('/^[a-z][a-z0-9.]{1,80}$/D',$key))throw new RuntimeException('Invalid mail template');
        $locale=$locale??(string)env('MAIL_LOCALE',env('APP_LOCALE','en'));
        if(!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D',$locale))$locale='en';
        $file=base_path('resources/mail/'.$locale.'.json');
        if(!is_file($file))$file=base_path('resources/mail/en.json');
        $catalog=json_decode((string)file_get_contents($file),true,16,JSON_THROW_ON_ERROR);
        $item=$catalog[$key]??null;
        if(!is_array($item)||!isset($item['subject'],$item['body']))throw new RuntimeException('Mail template unavailable: '.$key);
        $result=[];
        foreach(['subject','body'] as $field){
            $source=(string)$item[$field];
            $result[$field]=preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/',static function(array $m)use($values):string {
                if(!array_key_exists($m[1],$values)||!is_scalar($values[$m[1]]))throw new RuntimeException('Missing mail variable');
                $v=(string)$values[$m[1]];
                if(strlen($v)>8192||str_contains($v,"\0"))throw new RuntimeException('Invalid mail variable');
                return $v;
            },$source);
        }
        return $result;
    }
}
