<?php
declare(strict_types=1);
namespace Brightery\Core\Events;
/** Upgrade-safe lifecycle and data-filter hooks. */
final class Hooks {
    private array $events=[]; private array $filters=[];
    public function listen(string $event,callable $listener,int $priority=0):void{$this->events[$event][]=['priority'=>$priority,'handler'=>$listener];}
    public function emit(string $event,mixed ...$arguments):void {
        $entries=$this->events[$event]??[];usort($entries,static fn($a,$b)=>$b['priority']<=>$a['priority']);
        foreach($entries as $entry)($entry['handler'])(...$arguments);
    }
    public function filter(string $name,callable $filter,int $priority=0):void {$this->filters[$name][]=['priority'=>$priority,'handler'=>$filter];}
    public function apply(string $name,mixed $value,mixed ...$arguments):mixed {
        $entries=$this->filters[$name]??[];usort($entries,static fn($a,$b)=>$b['priority']<=>$a['priority']);
        foreach($entries as $entry)$value=($entry['handler'])($value,...$arguments);
        return $value;
    }
}
