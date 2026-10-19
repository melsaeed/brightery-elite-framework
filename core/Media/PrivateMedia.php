<?php
declare(strict_types=1);
namespace Brightery\Core\Media;
use Brightery\Core\Database;
use Brightery\Core\Jobs\Queue;
use Brightery\Core\Media\Scan\ScannerInterface;
use Brightery\Core\Tenancy\ContextStore;
use RuntimeException;
/** Tenant-scoped private file handling. Only server-side MIME sniffing, no executable formats. */
final class PrivateMedia {
    public const ALLOWED=['image/jpeg','image/png','image/webp','application/pdf'];
    public function __construct(private readonly Database $db,private readonly ContextStore $context,private readonly MediaQuota $quota,private readonly ScannerInterface $scanner,private readonly Queue $queue){}
    public function scope():?int {return $this->context->current()->isTenant()?$this->context->current()->requireTenantId():null;}
    public static function validated(string $path,string $original,int $limit=10485760):array {
        if($path===''||!is_file($path)||is_link($path))throw new RuntimeException('Invalid upload file');
        $size=filesize($path);
        if($size===false||$size<1||$size>$limit)throw new RuntimeException('File size invalid');
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if(!in_array($mime,self::ALLOWED,true))throw new RuntimeException('Unsupported media format');
        if(str_starts_with($mime,'image/') && @getimagesize($path)===false)throw new RuntimeException('Invalid image');
        if($mime==='application/pdf' && file_get_contents($path,false,null,0,5)!=='%PDF-')throw new RuntimeException('Invalid PDF');
        $name=trim(basename(str_replace('\\','/',$original)));
        if($name===''||strlen($name)>255||preg_match('/[\x00-\x1F\x7F]/',$name))throw new RuntimeException('Invalid filename');
        return [$mime,$size,$name];
    }
    public function storeUploaded(string $path,string $original,int $userId):int {
        if(!is_uploaded_file($path))throw new RuntimeException('Untrusted upload source');
        return $this->store($path,$original,$userId,true);
    }
    /** fromUploaded=false exists for trusted CLI import only: never bind to arbitrary web input. */
    public function store(string $path,string $original,int $userId,bool $fromUploaded=false):int {
        if($userId<1)throw new RuntimeException('Login required');
        [$mime,$size,$name]=self::validated($path,$original);
        $this->scanner->scan($path); // fail closed if configured scanner is unavailable or rejects file
        $tenant=$this->scope();
        if($tenant!==null && !$this->db->row('SELECT 1 FROM tenant_memberships WHERE tenant_id=? AND user_id=?',[$tenant,$userId]))throw new RuntimeException('Uploader is not a member of this tenant');
        $directory=base_path('storage/private/media');
        if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Media directory not writable');
        $key=bin2hex(random_bytes(32));$target=$directory.'/'.$key;
        // Keep quota update, media insert and thumbnail queue enqueue in one transaction.
        // An upload source is consumed only after the reservation succeeds.
        try{
            return $this->db->transactional(function(Database $db)use($size,$fromUploaded,$path,$target,$tenant,$userId,$key,$name,$mime):int {
                $this->quota->reserve($size,true);
                $moved=$fromUploaded?move_uploaded_file($path,$target):copy($path,$target);
                if(!$moved)throw new RuntimeException('Unable to store media');
                @chmod($target,0600);
                $db->execute('INSERT INTO bf_private_media(tenant_id,uploader_id,storage_key,original_name,mime,bytes) VALUES(?,?,?,?,?,?)',[$tenant,$userId,$key,$name,$mime,$size]);
                $id=(int)$db->pdo()->lastInsertId();
                if(str_starts_with($mime,'image/')&&filter_var(env('MEDIA_AUTO_THUMBNAILS','true'),FILTER_VALIDATE_BOOLEAN)) {
                    $this->queue->dispatch('system.media.thumbnail',['media_id'=>$id],0,3);
                }
                return $id;
            });
        }catch(\Throwable $e){@unlink($target);throw $e;}
    }
    public function metadata(int $id):?array {
        if($id<1)return null;$tenant=$this->scope();
        return $tenant===null
          ?$this->db->row('SELECT * FROM bf_private_media WHERE id=? AND tenant_id IS NULL',[$id])
          :$this->db->row('SELECT * FROM bf_private_media WHERE id=? AND tenant_id=?',[$id,$tenant]);
    }
    public function list(int $limit=50):array {
        $limit=max(1,min(100,$limit));$tenant=$this->scope();
        return $tenant===null?$this->db->rows("SELECT id,original_name,mime,bytes,created_at FROM bf_private_media WHERE tenant_id IS NULL ORDER BY id DESC LIMIT $limit")
          :$this->db->rows("SELECT id,original_name,mime,bytes,created_at FROM bf_private_media WHERE tenant_id=? ORDER BY id DESC LIMIT $limit",[$tenant]);
    }
    public function read(int $id):?array {
        $row=$this->metadata($id);if(!$row)return null;
        $file=base_path('storage/private/media/'.$row['storage_key']);
        if(!is_file($file)||is_link($file))return null;
        return ['name'=>$row['original_name'],'mime'=>$row['mime'],'bytes'=>file_get_contents($file)];
    }
    public function delete(int $id):bool {
        if($id<1)return false;
        $tenant=$this->scope();
        $files=[];
        // Lock the parent media row so a concurrent variant builder cannot attach after our byte snapshot.
        $deleted=$this->db->transactional(function(Database $db)use($id,$tenant,&$files):bool {
            $row=$tenant===null
                ?$db->row('SELECT * FROM bf_private_media WHERE id=? AND tenant_id IS NULL FOR UPDATE',[$id])
                :$db->row('SELECT * FROM bf_private_media WHERE id=? AND tenant_id=? FOR UPDATE',[$id,$tenant]);
            if(!$row)return false;
            $variants=$db->rows('SELECT storage_key,bytes FROM bf_media_variants WHERE media_id=?',[$id]);
            $changed=$tenant===null?$db->execute('DELETE FROM bf_private_media WHERE id=? AND tenant_id IS NULL',[$id]):$db->execute('DELETE FROM bf_private_media WHERE id=? AND tenant_id=?',[$id,$tenant]);
            if($changed!==1)return false;
            $this->quota->release((int)$row['bytes'],true);
            foreach($variants as $variant)$this->quota->release((int)$variant['bytes'],false);
            $files=[(string)$row['storage_key'],...array_column($variants,'storage_key')];
            return true;
        });
        if($deleted)foreach($files as $key)@unlink(base_path('storage/private/media/'.$key));
        return $deleted;
    }
    public function bulkDelete(array $ids):int {
        if(count($ids)>50)throw new RuntimeException('Bulk removal limit exceeded');
        $total=0;
        foreach(array_unique($ids) as $id){
            if(!is_int($id)||$id<1)throw new RuntimeException('Invalid media identifier');
            if($this->delete($id))$total++;
        }
        return $total;
    }

}
