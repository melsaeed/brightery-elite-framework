<?php
declare(strict_types=1);
namespace Brightery\Core\Validation;
/** Small, deterministic validation engine; extend rules via application services. */
final class Validator {
    public function validate(array $data,array $rules): array {
        $errors=[];
        foreach($rules as $field=>$definition){
            $ruleSet=is_array($definition)?$definition:explode('|',(string)$definition);
            $value=$data[$field]??null;
            foreach($ruleSet as $rule){
                [$name,$arg]=array_pad(explode(':',(string)$rule,2),2,null);
                $required=$name==='required';
                if($required && ($value===null || $value==='' || (is_array($value)&&!$value))) {$errors[$field][]='This field is required';continue;}
                if($value===null || $value==='')continue;
                $invalid=match($name){
                    'required'=>false,
                    'string'=>!is_string($value),
                    'integer'=>filter_var($value,FILTER_VALIDATE_INT)===false,
                    'email'=>!is_string($value)||filter_var($value,FILTER_VALIDATE_EMAIL)===false,
                    'url'=>!is_string($value)||filter_var($value,FILTER_VALIDATE_URL)===false,
                    'boolean'=>!in_array($value,[true,false,0,1,'0','1'],true),
                    'min'=>!is_string($value)||mb_len($value)<(int)$arg,
                    'max'=>!is_string($value)||mb_len($value)>(int)$arg,
                    'in'=>!in_array($value,explode(',',(string)$arg),true),
                    'confirmed'=>!array_key_exists($field.'_confirmation',$data)||$value!==$data[$field.'_confirmation'],
                    default=>throw new \InvalidArgumentException('Unknown validation rule: '.$name)
                };
                if($invalid)$errors[$field][]='Invalid value for '.$name;
            }
        }
        return $errors;
    }
}
/** Unicode-aware length without a mandatory mbstring extension. */
function mb_len(string $s):int{return function_exists('mb_strlen')?mb_strlen($s,'UTF-8'):strlen($s);}
