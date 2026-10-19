<?php
declare(strict_types=1);
namespace Brightery\Core\Operations;
use Brightery\Core\Database;
use RuntimeException;

/** Read-only media inventory; repairs counter drift only with DB row locks and explicit opt-in. */
final class MediaReconciler {
    public function __construct(private readonly Database $db,private readonly string $directory){}
    public static function scope(int $scopeId):int {
        if($scopeId<0||$scopeId>2147483647)throw new RuntimeException('Invalid media scope');return $scopeId;
    }
    public static function safeKey(string $key):bool {return preg_match('/^[a-f0-9]{64}$/D',$key)===1;}
    public static function difference(array $recorded,array $expected):array {
        return [
            'used_bytes'=>(int)$expected['used_bytes']-(int)$recorded['used_bytes'],
            'original_count'=>(int)$expected['original_count']-(int)$recorded['original_count'],
            'variant_count'=>(int)$expected['variant_count']-(int)$recorded['variant_count'],
        ];
    }
    private function expected(int $scopeId):array {
        $key=$scopeId===0?'IS NULL':'= ?';$args=$scopeId===0?[]:[$scopeId];
        $a=$this->db->row("SELECT COUNT(*) n,COALESCE(SUM(bytes),0) bytes FROM bf_private_media WHERE tenant_id $key",$args);
        $v=$this->db->row("SELECT COUNT(*) n,COALESCE(SUM(bytes),0) bytes FROM bf_media_variants WHERE tenant_id $key",$args);
        return ['used_bytes'=>(int)$a['bytes']+(int)$v['bytes'],'original_count'=>(int)$a['n'],'variant_count'=>(int)$v['n']];
    }
    private function inventory(int $scopeId,int $limit):array {
        $key=$scopeId===0?'IS NULL':'= ?';$args=$scopeId===0?[]:[$scopeId];
        $limit=max(1,min(50000,$limit));
        $rows=$this->db->rows("SELECT storage_key,bytes FROM bf_private_media WHERE tenant_id $key ORDER BY id LIMIT ".($limit+1),$args);
        $variants=$this->db->rows("SELECT storage_key,bytes FROM bf_media_variants WHERE tenant_id $key ORDER BY id LIMIT ".($limit+1),$args);
        $truncated=count($rows)>$limit||count($variants)>$limit;
        $missing=[];$mismatch=[];$invalid=[];$checked=0;
        foreach([array_slice($rows,0,$limit),array_slice($variants,0,$limit)] as $items){
            foreach($items as $row){
                $key=(string)$row['storage_key'];
                if(!self::safeKey($key)){$invalid[]=$key;continue;}
                $path=rtrim($this->directory,'/').'/'.$key;$checked++;
                if(!is_file($path)||is_link($path)){$missing[]=$key;continue;}
                $bytes=filesize($path);
                if($bytes===false||$bytes!==(int)$row['bytes'])$mismatch[]=$key;
            }
        }
        return ['checked'=>$checked,'truncated'=>$truncated,'missing'=>$missing,'byte_mismatches'=>$mismatch,'invalid_keys'=>$invalid];
    }
    public function preview(int $scopeId,int $limit=10000):array {
        self::scope($scopeId);
        $expected=$this->expected($scopeId);
        $actual=$this->db->row('SELECT used_bytes,original_count,variant_count FROM bf_media_usage WHERE scope_id=?',[$scopeId])??['used_bytes'=>0,'original_count'=>0,'variant_count'=>0];
        $actual=array_map('intval',$actual);
        return ['scope_id'=>$scopeId,'recorded'=>$actual,'expected'=>$expected,'drift'=>self::difference($actual,$expected),'files'=>$this->inventory($scopeId,$limit)];
    }
    /** No destructive file operations. Concurrency controlled by the same quota row used by uploads. */
    public function repair(int $scopeId):array {
        self::scope($scopeId);
        if(PHP_SAPI!=='cli'||(string)env('MEDIA_RECONCILE_MAINTENANCE','false')!=='true')throw new RuntimeException('Reconciliation repair needs CLI and MEDIA_RECONCILE_MAINTENANCE=true');
        return $this->db->transactional(function(Database $db)use($scopeId):array {
            $db->execute('INSERT IGNORE INTO bf_media_usage(scope_id) VALUES(?)',[$scopeId]);
            $locked=$db->row('SELECT used_bytes,original_count,variant_count FROM bf_media_usage WHERE scope_id=? FOR UPDATE',[$scopeId]);
            $expected=$this->expected($scopeId);
            $db->execute('UPDATE bf_media_usage SET used_bytes=?,original_count=?,variant_count=? WHERE scope_id=?',[$expected['used_bytes'],$expected['original_count'],$expected['variant_count'],$scopeId]);
            return ['scope_id'=>$scopeId,'previous'=>array_map('intval',$locked),'expected'=>$expected,'drift'=>self::difference($locked,$expected),'applied'=>true];
        });
    }
}
