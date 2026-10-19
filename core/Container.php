<?php
declare(strict_types=1);
namespace Brightery\Core;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/** Service identity is a contract, not a PHP runtime monkey-patch. */
final class Container {
    private array $bindings=[];
    private array $instances=[];
    private array $scoped=[];
    private array $decorators=[];
    private array $aliases=[];
    private array $resolving=[];

    public function bind(string $id, callable|string $factory, bool $shared=true): void {
        $this->bindings[$id]=['factory'=>$factory,'lifetime'=>$shared?'singleton':'transient'];
        unset($this->instances[$id],$this->scoped[$id]);
    }
    public function scoped(string $id,callable|string $factory):void {
        $this->bindings[$id]=['factory'=>$factory,'lifetime'=>'scoped'];
        unset($this->instances[$id],$this->scoped[$id]);
    }
    public function instance(string $id,object $instance):void {$this->validateType($id,$instance);$this->instances[$id]=$instance;}
    public function alias(string $alias,string $id):void {if($alias===$id)throw new RuntimeException('Self alias is forbidden');$this->aliases[$alias]=$id;}
    public function extend(string $id,callable $decorator):void {$this->decorators[$id][]=$decorator;unset($this->instances[$id],$this->scoped[$id]);}
    public function has(string $id):bool {return isset($this->aliases[$id]) || isset($this->bindings[$id]) || isset($this->instances[$id]) || class_exists($id);}
    public function resetScope():void {$this->scoped=[];}
    public function get(string $id):object {
        $original=$id;
        $seen=[];
        while(isset($this->aliases[$id])){
            if(isset($seen[$id]))throw new RuntimeException('Circular container alias: '.$original);
            $seen[$id]=true;$id=$this->aliases[$id];
        }
        if(isset($this->instances[$id]))return $this->instances[$id];
        if(isset($this->scoped[$id]))return $this->scoped[$id];
        if(in_array($id,$this->resolving,true))throw new RuntimeException('Circular dependency: '.implode(' -> ',[...$this->resolving,$id]));
        $this->resolving[]=$id;
        try {
            $binding=$this->bindings[$id]??['factory'=>$id,'lifetime'=>'singleton'];
            $factory=$binding['factory'];
            $object=is_string($factory) ? $this->build($factory) : $factory($this);
            if(!is_object($object))throw new RuntimeException('Factory did not return an object: '.$id);
            foreach($this->decorators[$id]??[] as $decorator){$object=$decorator($object,$this);if(!is_object($object))throw new RuntimeException('Decorator did not return an object: '.$id);}
            $this->validateType($id,$object);
            if($binding['lifetime']==='singleton')$this->instances[$id]=$object;
            if($binding['lifetime']==='scoped')$this->scoped[$id]=$object;
            return $object;
        } finally {array_pop($this->resolving);}
    }
    private function validateType(string $id,object $object):void {
        if((class_exists($id)||interface_exists($id)) && !is_a($object,$id))throw new RuntimeException('Service '. $id.' violates its registered type');
    }
    private function build(string $class):object {
        if(!class_exists($class))throw new RuntimeException('Class not found: '.$class);
        $reflect=new ReflectionClass($class);
        if(!$reflect->isInstantiable())throw new RuntimeException('Cannot instantiate service: '.$class);
        $constructor=$reflect->getConstructor();if(!$constructor)return new $class();
        $args=[];
        foreach($constructor->getParameters() as $parameter){
            $type=$parameter->getType();
            if($type instanceof ReflectionNamedType && !$type->isBuiltin())$args[]=$this->get($type->getName());
            elseif($parameter->isDefaultValueAvailable())$args[]=$parameter->getDefaultValue();
            else throw new RuntimeException('Cannot resolve '.$class.'::$'.$parameter->getName());
        }
        return $reflect->newInstanceArgs($args);
    }
}
