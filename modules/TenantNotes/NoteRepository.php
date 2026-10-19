<?php
declare(strict_types=1);
namespace Modules\TenantNotes;
use Brightery\Core\Tenancy\TenantRecords;

final class NoteRepository {
    public function __construct(private readonly TenantRecords $records){}
    public function list():array {return $this->records->all('tenant_notes');}
    public function find(int $id):?array {return $this->records->find('tenant_notes',$id);}
    public function create(string $title,string $content):int {
        if(trim($title)===''||strlen($title)>160||strlen($content)>50000)throw new \InvalidArgumentException('Invalid note');
        return $this->records->insert('tenant_notes',['title'=>$title,'content'=>$content]);
    }
    public function update(int $id,string $title,string $content):int {return $this->records->update('tenant_notes',$id,['title'=>$title,'content'=>$content]);}
    public function delete(int $id):int {return $this->records->delete('tenant_notes',$id);}
}
