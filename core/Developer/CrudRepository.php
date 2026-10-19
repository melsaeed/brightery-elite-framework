<?php
declare(strict_types=1);
namespace Brightery\Core\Developer;
use Brightery\Core\Database;
use Brightery\Core\Tenancy\TenantRecords;
use InvalidArgumentException;

/** Query identifiers come only from a normalized, installed module definition. */
class CrudRepository {
    public function __construct(private readonly Database $db,private readonly TenantRecords $tenantRecords,private readonly array $schema,private readonly ?CrudValidationPolicy $policy=null,private readonly ?string $moduleRoot=null){
        $validated=ModuleBlueprint::normalize(array_intersect_key($schema,array_flip(['name','title','scope','fields','soft_delete'])));
        if($validated!==$schema)throw new InvalidArgumentException('Invalid module schema');
    }
    public function schema():array{return $this->schema;}
    private function table():string{return '`'.$this->schema['table'].'`';}
    private function tenant():bool{return $this->schema['scope']==='tenant';}
    public function softDeleteEnabled():bool{return ($this->schema['soft_delete']??false)===true;}
    private function recordWhere(bool $trashed=false):array {
        $where=['`deleted_at` IS '.($trashed?'NOT NULL':'NULL')];$params=[];
        if($this->tenant()){$where[]='`tenant_id`=?';$params[]=$this->tenantRecords->tenantId();}
        return [implode(' AND ',$where),$params];
    }
    public function trashed(int $id):?array {
        if(!$this->softDeleteEnabled()||$id<1)throw new InvalidArgumentException('Trash unavailable');
        [$where,$params]=$this->recordWhere(true);
        return $this->db->row('SELECT * FROM '.$this->table().' WHERE `id`=? AND '.$where.' LIMIT 1',[$id,...$params]);
    }
    public function restore(int $id):int {
        if(!$this->softDeleteEnabled()||$id<1)throw new InvalidArgumentException('Restore unavailable');
        [$where,$params]=$this->recordWhere(true);
        return $this->db->execute('UPDATE '.$this->table().' SET `deleted_at`=NULL WHERE `id`=? AND '.$where,[$id,...$params]);
    }
    public function listing():array{
        return $this->softDeleteEnabled()?$this->page(['per_page'=>50])['data']:($this->tenant()?$this->tenantRecords->all($this->schema['table'],50):$this->db->rows('SELECT * FROM '.$this->table().' ORDER BY id DESC LIMIT 50'));
    }
    /** Strictly bounded and allowlisted pagination; no request-provided SQL identifiers. */
    public function page(array $query=[]):array {
        if(array_diff(array_keys($query),['page','search','filter','value','per_page','sort','direction','view']))throw new InvalidArgumentException('Unknown query parameter');
        $p=filter_var($query['page']??1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10000]]);
        $n=filter_var($query['per_page']??20,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>100]]);
        if($p===false||$n===false)throw new InvalidArgumentException('Invalid pagination');
        $sort=$query['sort']??'id';$rawDirection=$query['direction']??'DESC';
        if(!is_string($rawDirection))throw new InvalidArgumentException('Invalid direction');
        $direction=strtoupper($rawDirection);
        $allowedSort=['id',...array_column(array_filter($this->schema['fields'],static fn($f)=>$f['type']!=='many_to_many'),'name')];
        if(!is_string($sort)||!in_array($sort,$allowedSort,true)||!in_array($direction,['ASC','DESC'],true))throw new InvalidArgumentException('Invalid sort');
        $sortSql='`'.$sort.'` '.$direction.($sort==='id'?'':', `id` DESC');
        $search=$query['search']??'';
        if(!is_string($search)||strlen($search)>100||str_contains($search,"\0"))throw new InvalidArgumentException('Invalid search');
        $filter=$query['filter']??null;$value=$query['value']??null;
        $types=array_column(array_filter($this->schema['fields'],static fn($f)=>$f['type']!=='many_to_many'),'type','name');
        if($filter!==null){
            if(!is_string($filter)||!isset($types[$filter])||!is_scalar($value))throw new InvalidArgumentException('Invalid filter');
            $match= $this->validated([$filter=>$value],true,false);$value=$match[$filter];
            if($value===null)throw new InvalidArgumentException('Null equality filter unsupported');
        }elseif($value!==null)throw new InvalidArgumentException('Filter value requires field');
        $view=$query['view']??'active';
        if(!is_string($view)||!in_array($view,['active','trash'],true)||($view==='trash'&&!$this->softDeleteEnabled()))throw new InvalidArgumentException('Invalid record view');
        $searchable=[];
        foreach($types as $name=>$type)if(in_array($type,['text','textarea','email'],true))$searchable[]=$name;
        if($this->tenant()&&!$this->softDeleteEnabled())return $this->tenantRecords->page($this->schema['table'],$searchable,(int)$p,(int)$n,$search,$filter,$value,$sort,$direction);
        $clauses=[];$params=[];
        if($this->softDeleteEnabled()){
            [$softWhere,$scopeParams]=$this->recordWhere($view==='trash');
            $clauses[]=$softWhere;array_push($params,...$scopeParams);
        }
        if($search!==''&&$searchable){$clauses[]='('.implode(' OR ',array_map(static fn($c)=>'LOCATE(?, `'.$c.'`) > 0',$searchable)).')';array_push($params,...array_fill(0,count($searchable),$search));}
        if($filter!==null){$clauses[]='`'.$filter.'`=?';$params[]=$value;}
        $where=$clauses?' WHERE '.implode(' AND ',$clauses):'';
        $total=(int)($this->db->row('SELECT COUNT(*) AS c FROM '.$this->table().$where,$params)['c']??0);
        $records=$this->db->rows('SELECT * FROM '.$this->table().$where.' ORDER BY '.$sortSql.' LIMIT '.(int)$n.' OFFSET '.(((int)$p-1)*(int)$n),$params);
        return ['data'=>$records,'total'=>$total,'page'=>(int)$p,'per_page'=>(int)$n,'pages'=>max(1,(int)ceil($total/(int)$n)),'sort'=>$sort,'direction'=>$direction,'view'=>$view];
    }
    public function find(int $id):?array{
        if($id<1)throw new InvalidArgumentException('Invalid ID');
        if($this->softDeleteEnabled()){
            [$where,$params]=$this->recordWhere();
            return $this->db->row('SELECT * FROM '.$this->table().' WHERE `id`=? AND '.$where.' LIMIT 1',[$id,...$params]);
        }
        return $this->tenant()?$this->tenantRecords->find($this->schema['table'],$id):$this->db->row('SELECT * FROM '.$this->table().' WHERE id=? LIMIT 1',[$id]);
    }
    /** Choices are always read through the active tenant boundary where applicable. */
    public function relationOptions(string $field,?int $include=null):array {
        $definition=null;
        foreach($this->schema['fields'] as $item)if($item['name']===$field && $item['type']==='relation')$definition=$item;
        if($definition===null)throw new InvalidArgumentException('Unknown relation field');
        $table=ModuleBlueprint::tableFor($definition['references']);
        $items=$this->tenant()?$this->tenantRecords->all($table,100):$this->db->rows('SELECT * FROM `'.$table.'` ORDER BY id DESC LIMIT 100');
        if($include!==null && $include>0 && !in_array($include,array_map(static fn($r)=>(int)$r['id'],$items),true)){
            $record=$this->tenant()?$this->tenantRecords->find($table,$include):$this->db->row('SELECT * FROM `'.$table.'` WHERE id=? LIMIT 1',[$include]);
            if($record)$items[]=$record;
        }
        $choices=[];
        foreach($items as $record){
            $display=$record['title']??$record['name']??$record['label']??('Record #'.$record['id']);
            $choices[]=['id'=>(int)$record['id'],'label'=>substr((string)$display,0,120)];
        }
        return $choices;
    }
    /** AJAX relation selector. The caller must additionally enforce the source and referenced module read permissions. */
    public function searchRelation(string $field,string $query='',?int $selected=null,int $limit=20,?string $moduleRoot=null):array {
        if(strlen($query)>80||str_contains($query,"\0")||$selected!==null&&$selected<1||$limit<1||$limit>30)throw new InvalidArgumentException('Invalid relation search');
        $relation=null;
        foreach($this->schema['fields'] as $definition)if($definition['name']===$field&&in_array($definition['type'],['relation','many_to_many'],true))$relation=$definition;
        if($relation===null)throw new InvalidArgumentException('Unknown relation field');
        $target=$relation['references'];$root=rtrim($moduleRoot??base_path('modules'),'/');
        $targetFile=$root.'/'.$target.'/blueprint.json';
        $targetSchema=ModuleBlueprint::fromFile($targetFile);
        if($targetSchema['name']!==$target||$targetSchema['scope']!==$this->schema['scope'])throw new InvalidArgumentException('Relation scope changed');
        $label=null;
        foreach(['title','name','label'] as $key)foreach($targetSchema['fields'] as $f){if($f['name']===$key&&in_array($f['type'],['text','email'],true)){$label=$key;break 2;}}
        if($label===null)foreach($targetSchema['fields'] as $f){if(in_array($f['type'],['text','email'],true)){$label=$f['name'];break;}}
        $table=$targetSchema['table'];$activeOnly=($targetSchema['soft_delete']??false)===true;$results=$this->tenant()
            ?$this->tenantRecords->searchChoices($table,$label,$query,$limit,$activeOnly)
            :$this->centralChoices($table,$label,$query,$limit,$activeOnly);
        $choices=[];$seen=[];
        foreach($results as $row){$id=(int)$row['id'];$seen[$id]=true;$choices[]=['id'=>$id,'label'=>substr((string)($row['choice_label']??('Record #'.$id)),0,120)];}
        if($selected!==null&&!isset($seen[$selected])){
            $row=$this->tenant()?$this->tenantRecords->find($table,$selected,$activeOnly):$this->db->row('SELECT * FROM `'.$table.'` WHERE id=?'.($activeOnly?' AND deleted_at IS NULL':'').' LIMIT 1',[$selected]);
            if($row)$choices[]=['id'=>$selected,'label'=>substr((string)($label!==null?$row[$label]:('Record #'.$selected)),0,120)];
        }
        return $choices;
    }
    private function centralChoices(string $table,?string $label,string $query,int $limit,bool $activeOnly=false):array {
        $col=$label!==null?'`'.$label.'`':null;
        $params=[];$where=$activeOnly?' WHERE deleted_at IS NULL':'';
        if($query!==''){
            if($col!==null){$where.=($where===''?' WHERE ':' AND ').'LOCATE(?, '.$col.') > 0';$params[]=$query;}
            elseif(ctype_digit($query)){$where.=($where===''?' WHERE ':' AND ').'id=?';$params[]=(int)$query;}
            else return [];
        }
        return $this->db->rows('SELECT id'.($col!==null?', '.$col.' AS choice_label':'').' FROM `'.$table.'`'.$where.' ORDER BY id DESC LIMIT '.$limit,$params);
    }
    /** Validate all IDs first, then delete inside a transaction; no partial cross-tenant bulk operation. */
    public function bulkDelete(array $ids):int {
        if(!array_is_list($ids)||count($ids)<1||count($ids)>50)throw new InvalidArgumentException('Bulk delete supports 1-50 rows');
        $clean=[];
        foreach($ids as $id){
            if(!is_int($id)&&!is_string($id))throw new InvalidArgumentException('Invalid bulk ID');
            $n=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($n===false||isset($clean[(int)$n]))throw new InvalidArgumentException('Invalid or duplicate bulk ID');
            $clean[(int)$n]=true;
        }
        return $this->db->transactional(function()use($clean):int {
            foreach(array_keys($clean) as $id)if($this->find($id)===null)throw new InvalidArgumentException('One or more records are unavailable');
            $deleted=0;
            foreach(array_keys($clean) as $id)$deleted+=$this->delete($id);
            if($deleted!==count($clean))throw new InvalidArgumentException('Bulk operation conflicted; retry');
            return $deleted;
        });
    }
    /** Return the selected records only within this source record's tenant. */
    public function relatedChoices(string $field,int $sourceId):array {
        $def=$this->relationField($field,true);
        if($sourceId<1||$this->find($sourceId)===null)throw new InvalidArgumentException('Source record unavailable');
        $target=ModuleBlueprint::fromFile(rtrim($this->moduleRoot??base_path('modules'),'/').'/'.$def['references'].'/blueprint.json');
        $label=null;
        foreach(['title','name','label'] as $preferred)foreach($target['fields'] as $candidate)if($candidate['name']===$preferred&&in_array($candidate['type'],['text','email'],true)){$label=$preferred;break 2;}
        if($label===null)foreach($target['fields'] as $candidate)if(in_array($candidate['type'],['text','email'],true)){$label=$candidate['name'];break;}
        $pivot='`'.ModuleBlueprint::pivotFor($this->schema['name'],$field).'`';
        $where='p.source_id=?';$params=[$sourceId];
        if($this->tenant()){$where.=' AND p.tenant_id=? AND t.tenant_id=?';$params[]=$this->tenantRecords->tenantId();$params[]=$this->tenantRecords->tenantId();}
        if($target['soft_delete']??false)$where.=' AND t.deleted_at IS NULL';
        $rows=$this->db->rows('SELECT t.id'.($label!==null?', t.`'.$label.'` AS label':'').' FROM '.$pivot.' p JOIN `'.$target['table'].'` t ON t.id=p.target_id WHERE '.$where.' ORDER BY t.id LIMIT 51',$params);
        if(count($rows)>50)throw new InvalidArgumentException('Too many saved relationships');
        return array_map(static fn($row)=>['id'=>(int)$row['id'],'label'=>substr((string)($row['label']??('Record #'.$row['id'])),0,120)],$rows);
    }
    private function relationField(string $field,bool $many=false):array {
        foreach($this->schema['fields'] as $def)if($def['name']===$field&&($many?$def['type']==='many_to_many':in_array($def['type'],['relation','many_to_many'],true)))return $def;
        throw new InvalidArgumentException('Unknown relationship field');
    }
    /** Bounded ID arrays; every target must be visible and active in the current scope. */
    private function validateRelationSet(array $field,mixed $raw):array {
        if(!is_array($raw)||!array_is_list($raw)||count($raw)>50)throw new InvalidArgumentException('Relationship must contain at most 50 IDs: '.$field['name']);
        $ids=[];
        foreach($raw as $id){
            if(!(is_int($id)||is_string($id))||!preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id))throw new InvalidArgumentException('Invalid relationship identifier');
            $id=(int)$id;if($id<1||isset($ids[$id]))throw new InvalidArgumentException('Duplicate or invalid relationship');
            $ids[$id]=true;
        }
        $target=ModuleBlueprint::fromFile(rtrim($this->moduleRoot??base_path('modules'),'/').'/'.$field['references'].'/blueprint.json');
        if($target['scope']!==$this->schema['scope'])throw new InvalidArgumentException('Cross-scope relationship');
        foreach(array_keys($ids) as $id){
            $activeOnly=($target['soft_delete']??false)===true;
            $exists=$this->tenant()?$this->tenantRecords->find($target['table'],$id,$activeOnly):$this->db->row('SELECT id FROM `'.$target['table'].'` WHERE id=?'.($activeOnly?' AND deleted_at IS NULL':'').' LIMIT 1',[$id]);
            if(!$exists)throw new InvalidArgumentException('Related record unavailable');
        }
        return array_keys($ids);
    }
    private function splitFields(array $validated):array {
        $multi=[];$scalar=[];
        foreach($this->schema['fields'] as $field){
            $key=$field['name'];if(!array_key_exists($key,$validated))continue;
            if($field['type']==='many_to_many')$multi[$key]=$validated[$key];else $scalar[$key]=$validated[$key];
        }
        return [$scalar,$multi];
    }
    private function syncRelationships(int $sourceId,array $multi):void {
        foreach($multi as $field=>$ids){
            $pivot='`'.ModuleBlueprint::pivotFor($this->schema['name'],$field).'`';
            $where='source_id=?';$args=[$sourceId];
            if($this->tenant()){$where.=' AND tenant_id=?';$args[]=$this->tenantRecords->tenantId();}
            $this->db->execute('DELETE FROM '.$pivot.' WHERE '.$where,$args);
            foreach($ids as $id){
                $cols=$this->tenant()?'(source_id,target_id,tenant_id)':'(source_id,target_id)';
                $params=$this->tenant()?[$sourceId,$id,$this->tenantRecords->tenantId()]:[$sourceId,$id];
                $this->db->execute('INSERT INTO '.$pivot.' '.$cols.' VALUES ('.implode(',',array_fill(0,count($params),'?')).')',$params);
            }
        }
    }
    /** Export never interpolates a client value into SQL, and mitigates spreadsheet formula execution. */
    public function csvRows(array $query=[]):array {
        $query['page']=1;$query['per_page']=100;
        $listing=$this->page($query);
        $headers=['id',...array_column(array_filter($this->schema['fields'],static fn($f)=>$f['type']!=='many_to_many'),'name')];
        $rows=[$headers];
        foreach($listing['data'] as $record){
            $cells=[];foreach($headers as $key){
                $cell=(string)($record[$key]??'');
                if(preg_match('/^[\x00-\x20]*[=+@\-]/',$cell)||preg_match('/^[\x00-\x1f]/',$cell))$cell="'".$cell;
                $cells[]=$cell;
            }$rows[]=$cells;
        }
        return $rows;
    }
    /** Restore an all-or-nothing bounded selection, without changing the tenant boundary. */
    public function bulkRestore(array $ids):int {
        if(!$this->softDeleteEnabled()||!array_is_list($ids)||count($ids)<1||count($ids)>50)throw new InvalidArgumentException('Invalid restore selection');
        $unique=[];
        foreach($ids as $id){
            if(!(is_int($id)||is_string($id))||!preg_match('/^[1-9][0-9]{0,17}$/D',(string)$id)||isset($unique[(int)$id]))throw new InvalidArgumentException('Invalid or duplicate restore identifier');
            $unique[(int)$id]=true;
        }
        return $this->db->transactional(function()use($unique):int {
            $restored=0;
            foreach(array_keys($unique) as $id){
                [$where,$params]=$this->recordWhere(true);
                if(!$this->db->row('SELECT id FROM '.$this->table().' WHERE id=? AND '.$where.' FOR UPDATE',[$id,...$params]))throw new InvalidArgumentException('Unrestorable record');
            }
            foreach(array_keys($unique) as $id)$restored+=$this->restore($id);
            if($restored!==count($unique))throw new InvalidArgumentException('Restore conflict');
            return $restored;
        });
    }
    /** Strict allow-list; unexpected inputs are rejected, never inserted as SQL columns. */
    public function validated(array $input,bool $partial=false,bool $applyPolicy=true):array{
        $allowed=array_column($this->schema['fields'],'name');
        if(array_diff(array_keys($input),$allowed))throw new InvalidArgumentException('Unexpected field');
        $values=[];
        foreach($this->schema['fields'] as $field){
            $name=$field['name'];$type=$field['type'];
            if($partial&&!array_key_exists($name,$input))continue;
            $raw=$input[$name]??null;
            if($type==='many_to_many'){
                $values[$name]=$this->validateRelationSet($field,$raw??[]);continue;
            }
            if($type==='boolean'){
                if($raw===null||$raw==='')$raw=0;
                if(!in_array($raw,[0,1,'0','1',false,true],true))throw new InvalidArgumentException('Invalid boolean: '.$name);
                $values[$name]=(int)$raw;continue;
            }
            if(!is_string($raw)&&!is_int($raw)&&!is_float($raw)&&$raw!==null)throw new InvalidArgumentException('Invalid value: '.$name);
            $value=$raw===null?'':trim((string)$raw);
            if($value===''){
                if($field['required'])throw new InvalidArgumentException('Required: '.$name);
                $values[$name]=null;continue;
            }
            $max=match($type){'textarea'=>5000,'email'=>190,'text'=>255,default=>64};
            if(strlen($value)>$max||str_contains($value,"\0"))throw new InvalidArgumentException('Value too long: '.$name);
            if($type==='relation'){
                if(!preg_match('/^[1-9][0-9]{0,17}$/D',$value))throw new InvalidArgumentException('Invalid related record: '.$name);
                $target=ModuleBlueprint::tableFor($field['references']);
                $ref=ModuleBlueprint::fromFile(rtrim($this->moduleRoot??base_path('modules'),'/').'/'.$field['references'].'/blueprint.json');
                if($ref['scope']!==$this->schema['scope'])throw new InvalidArgumentException('Cross-scope relation rejected');
                $activeOnly=($ref['soft_delete']??false)===true;
                $exists=$this->tenant()?$this->tenantRecords->find($target,(int)$value,$activeOnly):$this->db->row('SELECT id FROM `'.$target.'` WHERE id=?'.($activeOnly?' AND deleted_at IS NULL':'').' LIMIT 1',[(int)$value]);
                if(!$exists)throw new InvalidArgumentException('Related record not available: '.$name);
                $values[$name]=(int)$value;continue;
            }
            $rules=$field['rules']??[];
            if(isset($rules['min_length'])&&strlen($value)<$rules['min_length']||isset($rules['max_length'])&&strlen($value)>$rules['max_length'])throw new InvalidArgumentException('Length constraint violated: '.$name);
            if(isset($rules['min'])&&(float)$value<$rules['min']||isset($rules['max'])&&(float)$value>$rules['max'])throw new InvalidArgumentException('Numeric constraint violated: '.$name);
            if($type==='email'&&!filter_var($value,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Invalid email: '.$name);
            if($type==='integer'&&!preg_match('/^-?(?:0|[1-9][0-9]{0,14})$/D',$value))throw new InvalidArgumentException('Invalid integer: '.$name);
            if($type==='decimal'&&!preg_match('/^-?(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?$/D',$value))throw new InvalidArgumentException('Invalid decimal: '.$name);
            if($type==='date'&&(!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)||!checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4))))throw new InvalidArgumentException('Invalid date: '.$name);
            $values[$name]=$value;
        }
        if($partial && !$values)throw new InvalidArgumentException('No fields supplied');
        if($applyPolicy)$this->policy?->validate($this->schema,$values,$partial);
        return $values;
    }
    public function create(array $data):int{
        return $this->db->transactional(function()use($data):int {
            [$fields,$multi]=$this->splitFields($this->validated($data));
            if($this->tenant()){
                if($fields)$id=$this->tenantRecords->insert($this->schema['table'],$fields);
                else{$this->db->execute('INSERT INTO '.$this->table().' (`tenant_id`) VALUES (?)',[$this->tenantRecords->tenantId()]);$id=(int)$this->db->pdo()->lastInsertId();}
            }else{
                if($fields){$keys=array_keys($fields);$columns=implode(',',array_map(fn($key)=>'`'.$key.'`',$keys));
                    $this->db->execute('INSERT INTO '.$this->table().' ('.$columns.') VALUES ('.implode(',',array_fill(0,count($keys),'?')).')',array_values($fields));}
                else $this->db->execute('INSERT INTO '.$this->table().' () VALUES ()');
                $id=(int)$this->db->pdo()->lastInsertId();
            }
            $this->syncRelationships($id,$multi);
            return $id;
        });
    }
    public function update(int $id,array $data,bool $partial=false):int{
        if($id<1)throw new InvalidArgumentException('Invalid ID');
        return $this->db->transactional(function()use($id,$data,$partial):int {
            // Lock the source row: concurrent relationship updates cannot interleave.
            $where='id=?';$params=[$id];
            if($this->tenant()){$where.=' AND tenant_id=?';$params[]=$this->tenantRecords->tenantId();}
            if($this->softDeleteEnabled())$where.=' AND deleted_at IS NULL';
            if(!$this->db->row('SELECT id FROM '.$this->table().' WHERE '.$where.' FOR UPDATE',$params))return 0;
            [$fields,$multi]=$this->splitFields($this->validated($data,$partial));
            if($fields){
                $set=implode(',',array_map(fn($key)=>'`'.$key.'`=?',array_keys($fields)));
                $this->db->execute('UPDATE '.$this->table().' SET '.$set.' WHERE '.$where,[...array_values($fields),...$params]);
            }
            if($multi)$this->syncRelationships($id,$multi);
            return 1;
        });
    }
    public function delete(int $id):int{
        if($id<1)throw new InvalidArgumentException('Invalid ID');
        if($this->softDeleteEnabled()){
            [$where,$params]=$this->recordWhere();
            return $this->db->execute('UPDATE '.$this->table().' SET `deleted_at`=UTC_TIMESTAMP() WHERE `id`=? AND '.$where,[$id,...$params]);
        }
        // Join tables have ON DELETE CASCADE on the source and target records.
        return $this->tenant()?$this->tenantRecords->delete($this->schema['table'],$id):$this->db->execute('DELETE FROM '.$this->table().' WHERE id=?',[$id]);
    }
}
