<?php
declare(strict_types=1);
namespace Brightery\Core\Database;
use Brightery\Core\Database as Connection;
use InvalidArgumentException;
/** Safe, intentionally limited query builder. Identifiers are validated, values are bound. */
final class QueryBuilder {
    private array $where=[];private array $bindings=[];private ?int $limit=null;private int $offset=0;private string $order='';
    public function __construct(private readonly Connection $db,private readonly string $table){self::identifier($table);}
    private static function identifier(string $value):string {
        if(!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/',$value))throw new InvalidArgumentException('Unsafe SQL identifier');
        return '`'.$value.'`';
    }
    public function where(string $column,string $operator,mixed $value):self {
        $operators=['=','!=','<','>','<=','>=','LIKE'];if(!in_array(strtoupper($operator),$operators,true))throw new InvalidArgumentException('Invalid operator');
        $clone=clone $this;$clone->where[]=self::identifier($column).' '.strtoupper($operator).' ?';$clone->bindings[]=$value;return $clone;
    }
    public function orderBy(string $column,string $direction='ASC'):self {
        $direction=strtoupper($direction);if(!in_array($direction,['ASC','DESC'],true))throw new InvalidArgumentException('Invalid direction');
        $clone=clone $this;$clone->order=' ORDER BY '.self::identifier($column).' '.$direction;return $clone;
    }
    public function limit(int $n,int $offset=0):self {
        if($n<1||$n>1000||$offset<0)throw new InvalidArgumentException('Invalid pagination');
        $clone=clone $this;$clone->limit=$n;$clone->offset=$offset;return $clone;
    }
    public function get(array $columns=['*']):array {
        $cols=implode(',',array_map(static fn($c)=>$c==='*'?'*':self::identifier($c),$columns));
        $sql='SELECT '.$cols.' FROM '.self::identifier($this->table);
        if($this->where)$sql.=' WHERE '.implode(' AND ',$this->where);
        $sql.=$this->order;
        if($this->limit!==null)$sql.=' LIMIT '.$this->limit.' OFFSET '.$this->offset;
        return $this->db->rows($sql,$this->bindings);
    }
    public function first(array $columns=['*']):?array {$rows=$this->limit(1)->get($columns);return $rows[0]??null;}
}
