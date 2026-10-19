<?php
declare(strict_types=1);
namespace Brightery\Core\Developer;
use InvalidArgumentException;

/** Declarative, no executable PHP/SQL. Derived fields must match the canonical schema. */
final class ModuleBlueprint {
    public const TYPES=['text','textarea','email','integer','decimal','date','boolean','relation','many_to_many'];
    public static function tableFor(string $module):string {
        if(!preg_match('/^[A-Z][A-Za-z0-9]{1,35}$/D',$module))throw new InvalidArgumentException('Invalid reference module');
        return 'bf_gen_'.strtolower(preg_replace('/([a-z0-9])([A-Z])/','$1_$2',$module));
    }
    /** Deterministic, length-bounded join identifier. Schema identifiers are never supplied by requests. */
    public static function pivotFor(string $module,string $field):string {
        if(!preg_match('/^[A-Z][A-Za-z0-9]{1,35}$/D',$module)||!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$field))throw new InvalidArgumentException('Invalid pivot identity');
        return 'bf_link_'.substr(hash('sha256',$module.':'.$field),0,32);
    }
    public static function normalize(array $definition):array {
        if(array_key_exists('table',$definition)||array_key_exists('slug',$definition)){
            $input=$definition;unset($input['table'],$input['slug']);$normalized=self::normalize($input);
            if($normalized!==$definition)throw new InvalidArgumentException('Derived module fields cannot be modified');
            return $normalized;
        }
        if(array_is_list($definition)||count($definition)>5||array_diff(array_keys($definition),['name','title','scope','fields','soft_delete']))throw new InvalidArgumentException('Unknown module blueprint property');
        $name=$definition['name']??null;
        if(!is_string($name)||!preg_match('/^[A-Z][A-Za-z0-9]{1,35}$/D',$name)||in_array($name,['System','Api','Blog','TenantNotes'],true))throw new InvalidArgumentException('Invalid module name');
        $title=$definition['title']??null;
        if(!is_string($title)||trim($title)===''||strlen($title)>100||preg_match('/[\x00-\x1f\x7f]/',$title))throw new InvalidArgumentException('Invalid module title');
        $scope=$definition['scope']??null;
        if(!in_array($scope,['tenant','central'],true))throw new InvalidArgumentException('Invalid module scope');
        if(array_key_exists('soft_delete',$definition)&&$definition['soft_delete']!==true)throw new InvalidArgumentException('soft_delete must be true when supplied');
        $fields=$definition['fields']??null;
        if(!is_array($fields)||!array_is_list($fields)||count($fields)<1||count($fields)>24)throw new InvalidArgumentException('Module requires 1–24 fields');
        $out=[];$used=[];
        foreach($fields as $field){
            if(!is_array($field)||array_is_list($field)||array_diff(array_keys($field),['name','label','type','required','references','rules']))throw new InvalidArgumentException('Invalid field definition');
            $key=$field['name']??null;$label=$field['label']??null;$type=$field['type']??null;$required=$field['required']??false;
            if(!is_string($key)||!preg_match('/^[a-z][a-z0-9_]{0,39}$/D',$key)||in_array($key,['id','tenant_id','created_at','updated_at','deleted_at','_csrf'],true)||isset($used[$key]))throw new InvalidArgumentException('Invalid or duplicate field identifier');
            if(!is_string($label)||trim($label)===''||strlen($label)>100||preg_match('/[\x00-\x1f\x7f]/',$label))throw new InvalidArgumentException('Invalid label');
            if(!is_string($type)||!in_array($type,self::TYPES,true)||!is_bool($required))throw new InvalidArgumentException('Invalid field type or required flag');
            if($type==='many_to_many'&&$required)throw new InvalidArgumentException('Many-to-many fields cannot be required');
            $entry=['name'=>$key,'label'=>trim($label),'type'=>$type,'required'=>$required];
            if(in_array($type,['relation','many_to_many'],true)){
                $ref=$field['references']??null;
                if(!is_string($ref)||!preg_match('/^[A-Z][A-Za-z0-9]{1,35}$/D',$ref)||$ref===$name||strlen(self::tableFor($ref))>64)throw new InvalidArgumentException('Invalid relation target');
                $entry['references']=$ref;
            }elseif(array_key_exists('references',$field))throw new InvalidArgumentException('Only relationship fields can declare references');
            if(array_key_exists('rules',$field)){
                $rules=$field['rules'];
                if(!is_array($rules)||array_is_list($rules)||!$rules||count($rules)>2)throw new InvalidArgumentException('Invalid field rules');
                $allowed=in_array($type,['text','textarea','email'],true)?['min_length','max_length']:(in_array($type,['integer','decimal'],true)?['min','max']:[]);
                if(!$allowed||array_diff(array_keys($rules),$allowed))throw new InvalidArgumentException('Rules are not available for this field');
                // Numeric bounds must be finite and precisely representable by the existing SQL field type.
                $normalizedRules=[];
                foreach($rules as $rule=>$bound){
                    if(!is_int($bound)||($rule==='min_length'||$rule==='max_length')&&($bound<0||$bound>5000)||in_array($rule,['min','max'],true)&&($bound < -9999999||$bound > 9999999))throw new InvalidArgumentException('Invalid rule bound');
                    $normalizedRules[$rule]=$bound;
                }
                if(isset($normalizedRules['min_length'],$normalizedRules['max_length'])&&$normalizedRules['min_length']>$normalizedRules['max_length']||isset($normalizedRules['min'],$normalizedRules['max'])&&$normalizedRules['min']>$normalizedRules['max'])throw new InvalidArgumentException('Inverted field rule bounds');
                $maxLength=match($type){'text'=>255,'email'=>190,'textarea'=>5000,default=>5000};
                if(($normalizedRules['min_length']??0)>$maxLength||($normalizedRules['max_length']??0)>$maxLength)throw new InvalidArgumentException('Rule exceeds storage limit');
                $entry['rules']=$normalizedRules;
            }
            $used[$key]=true;$out[]=$entry;
        }
        $slug=strtolower(preg_replace('/([a-z0-9])([A-Z])/','$1_$2',$name));
        $table='bf_gen_'.$slug;
        if(strlen($table)>64)throw new InvalidArgumentException('Module table name too long');
        $normalized=['name'=>$name,'title'=>trim($title),'scope'=>$scope,'table'=>$table,'slug'=>str_replace('_','-',$slug),'fields'=>$out];
        if(($definition['soft_delete']??false)===true)$normalized['soft_delete']=true;
        return $normalized;
    }
    public static function fromFile(string $file):array {
        if(is_link($file)||!is_file($file)||filesize($file)>32768)throw new InvalidArgumentException('Invalid module blueprint file');
        $data=json_decode((string)file_get_contents($file),true,20,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new InvalidArgumentException('Invalid module blueprint data');
        $normalized=self::normalize(array_intersect_key($data,array_flip(['name','title','scope','fields','soft_delete'])));
        if($data!==$normalized)throw new InvalidArgumentException('Module blueprint was modified or is incompatible');
        return $normalized;
    }
}
