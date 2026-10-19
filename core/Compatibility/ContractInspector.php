<?php
declare(strict_types=1);
namespace Brightery\Core\Compatibility;

use Brightery\Core\Modules\ModuleManager;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/** The v0.2.0 module API is separately versioned from Brightery Framework releases. */
final class ContractInspector {
    public function __construct(private readonly string $manifest=__DIR__.'/../../resources/contracts/module-api-0.2.0.json'){}
    public function check():array {
        $raw=file_get_contents($this->manifest);
        if($raw===false)throw new RuntimeException('Missing extension API manifest');
        $data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(($data['api_version']??null)!==ModuleManager::API_VERSION)throw new RuntimeException('Module API version mismatch');
        $checked=0;$violations=[];
        foreach($data['contracts']??[] as $class=>$methods){
            if(!class_exists($class)&&!interface_exists($class)){$violations[]='Missing type: '.$class;continue;}
            $reflect=new ReflectionClass($class);
            foreach($methods as $method=>$details){
                if(!$reflect->hasMethod($method)){$violations[]="Missing method: $class::$method";continue;}
                $ref=$reflect->getMethod($method);$checked++;
                if(!$ref->isPublic())$violations[]="Non-public method: $class::$method";
                if($ref->getNumberOfRequiredParameters()>(int)$details['required_max'])$violations[]="Too many required parameters: $class::$method";
                if($ref->getNumberOfParameters()<(int)$details['parameters_min'])$violations[]="Missing parameters: $class::$method";
                if(($details['static']??false)!==$ref->isStatic())$violations[]="Static method mismatch: $class::$method";
            }
        }
        return ['api_version'=>ModuleManager::API_VERSION,'checked_methods'=>$checked,'compatible'=>$violations===[],'violations'=>$violations];
    }
}
