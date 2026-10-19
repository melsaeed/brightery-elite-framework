<?php
declare(strict_types=1);
namespace Brightery\Core\Tenancy;
use Brightery\Core\Database;
use InvalidArgumentException;

/** Bounded, tenant-filtered data access. Never pass externally supplied table/column identifiers. */
final class TenantRecords {
    public function __construct(private readonly Database $db,private readonly ContextStore $contexts){}
    private function ident(string $name):string {
        if(!preg_match('/^[a-z][a-z0-9_]*$/D',$name))throw new InvalidArgumentException('Unsafe SQL identifier');
        if(in_array($name,['tenant_id','id'],true))throw new InvalidArgumentException('tenant_id is managed by the context');
        return '`'.$name.'`';
    }
    private function table(string $name):string {return $this->ident($name);}
    /** Server-resolved tenant ID; never accept a browser-provided tenant ID. */
    public function tenantId():int{return $this->contexts->current()->requireTenantId();}
    public function all(string $table,int $limit=50):array {
        $id=$this->contexts->current()->requireTenantId();
        $limit=max(1,min(100,$limit));
        return $this->db->rows('SELECT * FROM '.$this->table($table).' WHERE tenant_id=? ORDER BY id DESC LIMIT '.$limit,[$id]);
    }
    /** Bounded paging with allowlisted field identifiers and tenant predicate in both queries. */
    public function page(string $table,array $searchable,int $page=1,int $perPage=20,?string $search=null,?string $filter=null,mixed $value=null,string $sort="id",string $direction="DESC"):array {
        $id=$this->contexts->current()->requireTenantId();
        $page=max(1,min(10000,$page));$perPage=max(1,min(100,$perPage));
        $where=['tenant_id=?'];$params=[$id];
        if($search!==null && $search!==''){
            $columns=array_map($this->ident(...),$searchable);
            if($columns){$where[]='('.implode(' OR ',array_map(static fn($c)=>'LOCATE(?, '.$c.') > 0',$columns)).')';
                array_push($params,...array_fill(0,count($columns),$search));}
        }
        if($filter!==null){$where[]=$this->ident($filter).'=?';$params[]=$value;}
        $predicate=implode(' AND ',$where);$t=$this->table($table);
        $total=(int)($this->db->row('SELECT COUNT(*) AS c FROM '.$t.' WHERE '.$predicate,$params)['c']??0);
        $sortColumn=$sort==='id'?'`id`':$this->ident($sort);
        if(!in_array($direction,['ASC','DESC'],true))throw new InvalidArgumentException('Invalid sort direction');
        $offset=($page-1)*$perPage;
        $rows=$this->db->rows('SELECT * FROM '.$t.' WHERE '.$predicate.' ORDER BY '.$sortColumn.' '.$direction.($sort==='id'?'':', `id` DESC').' LIMIT '.$perPage.' OFFSET '.$offset,$params);
        return ['data'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$perPage,'pages'=>max(1,(int)ceil($total/$perPage))];
    }
    /** Search the current tenant's relation records only; bounded and parameterized. */
    public function searchChoices(string $table,?string $labelColumn,string $query,int $limit=20,bool $activeOnly=false):array {
        $id=$this->contexts->current()->requireTenantId();
        $limit=max(1,min(30,$limit));$t=$this->table($table);
        $display=$labelColumn!==null?$this->ident($labelColumn):null;
        $where='tenant_id=?'.($activeOnly?' AND deleted_at IS NULL':'');$params=[$id];
        if($query!==''){
            if($display!==null){$where.=' AND LOCATE(?, '.$display.') > 0';$params[]=$query;}
            elseif(ctype_digit($query)){$where.=' AND id=?';$params[]=(int)$query;}
            else return [];
        }
        $sql='SELECT id'.($display!==null?', '.$display.' AS choice_label':'').' FROM '.$t.' WHERE '.$where.' ORDER BY id DESC LIMIT '.$limit;
        return $this->db->rows($sql,$params);
    }
    public function find(string $table,int $recordId,bool $activeOnly=false):?array {
        $id=$this->contexts->current()->requireTenantId();
        return $this->db->row('SELECT * FROM '.$this->table($table).' WHERE id=? AND tenant_id=?'.($activeOnly?' AND deleted_at IS NULL':'').' LIMIT 1',[$recordId,$id]);
    }
    public function insert(string $table,array $fields):int {
        $id=$this->contexts->current()->requireTenantId();
        if(!$fields)throw new InvalidArgumentException('Insert requires fields');
        $cols=array_map($this->ident(...),array_keys($fields));
        $sql='INSERT INTO '.$this->table($table).' ('.implode(',',$cols).',`tenant_id`) VALUES ('.implode(',',array_fill(0,count($fields)+1,'?')).')';
        $this->db->execute($sql,[...array_values($fields),$id]);
        return (int)$this->db->pdo()->lastInsertId();
    }
    public function update(string $table,int $recordId,array $fields):int {
        $id=$this->contexts->current()->requireTenantId();
        if(!$fields)throw new InvalidArgumentException('Update requires fields');
        $set=array_map(fn($field)=>$this->ident($field).'=?',array_keys($fields));
        return $this->db->execute('UPDATE '.$this->table($table).' SET '.implode(',',$set).' WHERE id=? AND tenant_id=?',[...array_values($fields),$recordId,$id]);
    }
    public function delete(string $table,int $recordId):int {
        $id=$this->contexts->current()->requireTenantId();
        return $this->db->execute('DELETE FROM '.$this->table($table).' WHERE id=? AND tenant_id=?',[$recordId,$id]);
    }
}
