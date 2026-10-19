<?php
declare(strict_types=1);
namespace Brightery\Core\Form;
use InvalidArgumentException;
use RuntimeException;

/** Declarative, validated form definitions. Stored under private, application-owned storage. */
class FormBlueprint {
    public const TYPES=['text','email','number','date','textarea','select','checkbox','file','autocomplete'];
    public function __construct(private readonly ?string $directory=null,private readonly ?ControlRegistry $controls=null){}
    private function dir():string {return $this->directory??base_path('storage/private/forms');}
    private static function slug(string $name):string {
        if(!preg_match('/^[a-z][a-z0-9_-]{0,63}$/D',$name))throw new InvalidArgumentException('Invalid form slug');
        return $name;
    }
    /** Avoid arbitrary HTML, external AJAX endpoints, duplicate names and unbounded schemas. */
    public function normalize(array $schema):array {
        if(count($schema)>4 || !isset($schema['title'],$schema['fields']) || !is_string($schema['title']) || !is_array($schema['fields']) || !array_is_list($schema['fields']))throw new InvalidArgumentException('Invalid form structure');
        foreach(array_keys($schema) as $key)if(!in_array($key,['title','fields'],true))throw new InvalidArgumentException('Unknown form property');
        $title=trim($schema['title']);if($title===''||strlen($title)>120)throw new InvalidArgumentException('Form title is required (max 120 characters)');
        if(count($schema['fields'])<1||count($schema['fields'])>40)throw new InvalidArgumentException('Form needs 1–40 fields');
        $used=[];$fields=[];
        foreach($schema['fields'] as $i=>$field){
            if(!is_array($field) || array_is_list($field) || count($field)>9)throw new InvalidArgumentException('Invalid field at position '.($i+1));
            $name=$field['name']??null;$label=$field['label']??null;$type=$field['type']??null;
            if(!is_string($name)||!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$name)||in_array($name,['csrf','_csrf'],true)||isset($used[$name]))throw new InvalidArgumentException('Invalid or duplicate field name at position '.($i+1));
            if(!is_string($label)||trim($label)===''||strlen($label)>120)throw new InvalidArgumentException('Invalid field label: '.$name);
            if(!is_string($type)||!in_array($type,self::TYPES,true))throw new InvalidArgumentException('Unsupported field type: '.$name);
            $required=$field['required']??false;
            if(!is_bool($required))throw new InvalidArgumentException('required must be boolean: '.$name);
            $normalized=['name'=>$name,'label'=>trim($label),'type'=>$type,'required'=>$required];
            if($type==='select'){
                $options=$field['options']??null;
                if(!is_array($options)||array_is_list($options)||count($options)<1||count($options)>30)throw new InvalidArgumentException('Select requires 1–30 options: '.$name);
                $clean=[];
                foreach($options as $key=>$value){if(!is_string($key)||!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$key)||!is_string($value)||$value===''||strlen($value)>120)throw new InvalidArgumentException('Invalid select option: '.$name);$clean[$key]=$value;}
                $normalized['options']=$clean;
            }
            if($type==='autocomplete'){
                $endpoint=$field['endpoint']??null;
                if(!is_string($endpoint)||!preg_match('~^/(?!/)[A-Za-z0-9/_-]{1,200}$~D',$endpoint))throw new InvalidArgumentException('Autocomplete requires a safe same-origin path: '.$name);
                $normalized['endpoint']=$endpoint;
            }
            if($type==='file'){
                $multiple=$field['multiple']??false;
                if(!is_bool($multiple))throw new InvalidArgumentException('multiple must be boolean: '.$name);
                $normalized['multiple']=$multiple;
            }
            $used[$name]=true;$fields[]=$normalized;
        }
        return ['title'=>$title,'fields'=>$fields];
    }
    public function render(array $schema):string {
        $schema=$this->normalize($schema);
        $html='<div class="bf-blueprint"><h3>'.esc($schema['title']).'</h3>';
        $registry=$this->controls??new ControlRegistry();
        foreach($schema['fields'] as $field){
            $name=$field['name'];$label=$field['label'];$type=$field['type'];$required=$field['required'];
            if($type==='text')$html.=$registry->render('text',['name'=>$name,'label'=>$label,'attrs'=>$required?['required'=>'required']:[]]);
            elseif($type==='select')$html.=$registry->render('select',['name'=>$name,'label'=>$label,'options'=>$field['options']]);
            elseif($type==='file')$html.=$registry->render('file',['name'=>$name,'label'=>$label,'multiple'=>$field['multiple']]);
            elseif($type==='autocomplete')$html.=$registry->render('autocomplete',['name'=>$name,'label'=>$label,'endpoint'=>$field['endpoint']]);
            elseif($type==='checkbox')$html.='<label class="bf-field bf-check"><input type="checkbox" name="'.esc($name).'" value="1"'.($required?' required':'').'><span>'.esc($label).'</span></label>';
            elseif($type==='textarea')$html.='<label class="bf-field"><span>'.esc($label).'</span><textarea name="'.esc($name).'" rows="3"'.($required?' required':'').'></textarea></label>';
            else $html.='<label class="bf-field"><span>'.esc($label).'</span><input type="'.$type.'" name="'.esc($name).'"'.($required?' required':'').'></label>';
        }
        return $html.'</div>';
    }
    /** Intended for use before application-specific business handling, not a replacement for authorization. */
    public function validateSubmission(array $schema,array $data):array {
        $schema=$this->normalize($schema);$errors=[];
        foreach($schema['fields'] as $field){
            $name=$field['name'];$type=$field['type'];$value=$data[$name]??null;
            if($field['required']&&($value===null||$value===''||$value===[]||$value===false)){$errors[$name][]='Required';continue;}
            if($value===null||$value===''||$value===false)continue;
            if($type==='file'){ // Files must be separately validated by the private media service.
                continue;
            }
            if(!is_scalar($value)){$errors[$name][]='Invalid value';continue;}
            $text=(string)$value;
            if(strlen($text)>4096 || ($type==='email'&&!filter_var($text,FILTER_VALIDATE_EMAIL)) || ($type==='number'&&!is_numeric($text)) || ($type==='date' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$text)||!checkdate((int)substr($text,5,2),(int)substr($text,8,2),(int)substr($text,0,4)))) || ($type==='select'&&!array_key_exists($text,$field['options'])) || ($type==='checkbox'&&!in_array($text,['0','1'],true))){$errors[$name][]='Invalid value';}
        }
        return $errors;
    }
    public function save(string $name,array $schema):void {
        $name=self::slug($name);$schema=$this->normalize($schema);$dir=$this->dir();
        if(is_link($dir))throw new RuntimeException('Forms directory cannot be a symlink');
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Cannot create private forms directory');
        $path=$dir.'/'.$name.'.json';if(is_link($path))throw new RuntimeException('Form symlink is not permitted');
        $lock=fopen($dir.'/.lock','c');if($lock===false)throw new RuntimeException('Cannot lock form definitions');
        try{
            if(!flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock form definitions');
            $tmp=tempnam($dir,'form-');if($tmp===false)throw new RuntimeException('Cannot create form');
            try{$json=json_encode($schema,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                if(file_put_contents($tmp,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('Form write failed');
                chmod($tmp,0600);if(!rename($tmp,$path))throw new RuntimeException('Form publish failed');
            }finally{if(is_file($tmp))unlink($tmp);}
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
    public function load(string $name):?array {
        $name=self::slug($name);$path=$this->dir().'/'.$name.'.json';
        if(!is_file($path))return null;if(is_link($path))throw new RuntimeException('Invalid form file');
        $raw=file_get_contents($path);if($raw===false||strlen($raw)>65536)throw new RuntimeException('Invalid form size');
        return $this->normalize(json_decode($raw,true,20,JSON_THROW_ON_ERROR));
    }
    public function listing():array {
        $names=[];foreach(glob($this->dir().'/*.json')?:[] as $path){if(!is_link($path))$names[]=basename($path,'.json');}
        sort($names);return array_slice($names,0,200);
    }
}
