<?php
declare(strict_types=1);
namespace Brightery\Core\Media;
use Brightery\Core\Database;
use Brightery\Core\Tenancy\ContextStore;
use RuntimeException;
/** Atomic, per-scope storage accounting. Scope 0 is central; positive IDs are tenants.
 * All modifications MUST be executed inside the same transaction as the media record.
 */
class MediaQuota {
    public function __construct(protected readonly Database $db,protected readonly ContextStore $context){}
    public function scopeId():int {
        $c=$this->context->current();return $c->isTenant()?$c->requireTenantId():0;
    }
    public static function positiveLimit(mixed $value,int $fallback):int {
        return is_scalar($value)&&preg_match('/^[0-9]{1,15}$/D',(string)$value)&& (int)$value>0?(int)$value:$fallback;
    }
    private function defaults():array {
        return [self::positiveLimit(env('MEDIA_QUOTA_BYTES'),536870912),self::positiveLimit(env('MEDIA_QUOTA_FILES'),2000)];
    }
    public function snapshot():array {
        $id=$this->scopeId();[$defBytes,$defFiles]=$this->defaults();
        $row=$this->db->row('SELECT u.used_bytes,u.original_count,u.variant_count,q.max_bytes,q.max_files FROM bf_media_usage u LEFT JOIN bf_media_quotas q ON q.scope_id=u.scope_id WHERE u.scope_id=?',[$id]);
        return ['scope_id'=>$id,'used_bytes'=>(int)($row['used_bytes']??0),'original_count'=>(int)($row['original_count']??0),'variant_count'=>(int)($row['variant_count']??0),'max_bytes'=>(int)($row['max_bytes']??$defBytes),'max_files'=>(int)($row['max_files']??$defFiles)];
    }
    /** Serialize reservations with row-level locks. Must be in one DB transaction with INSERT. */
    public function reserve(int $bytes,bool $original):void {
        if(!$this->db->pdo()->inTransaction())throw new RuntimeException('Quota reservations require a database transaction');
        if($bytes<1||$bytes>10485760)throw new RuntimeException('Invalid media bytes');
        $id=$this->scopeId();
        $this->db->execute('INSERT IGNORE INTO bf_media_usage(scope_id) VALUES(?)',[$id]);
        $current=$this->db->row('SELECT used_bytes,original_count FROM bf_media_usage WHERE scope_id=? FOR UPDATE',[$id]);
        $limits=$this->db->row('SELECT max_bytes,max_files FROM bf_media_quotas WHERE scope_id=?',[$id]);
        [$defaultBytes,$defaultFiles]=$this->defaults();
        $capBytes=(int)($limits['max_bytes']??$defaultBytes);$capFiles=(int)($limits['max_files']??$defaultFiles);
        if((int)$current['used_bytes']>$capBytes-$bytes || ($original&&(int)$current['original_count']>=$capFiles))throw new RuntimeException('Media storage quota exceeded');
        $this->db->execute('UPDATE bf_media_usage SET used_bytes=used_bytes+?,original_count=original_count+?,variant_count=variant_count+? WHERE scope_id=?',[$bytes,(int)$original,(int)!$original,$id]);
    }
    /** Must run within a transaction with the corresponding DELETE. */
    public function release(int $bytes,bool $original):void {
        if(!$this->db->pdo()->inTransaction())throw new RuntimeException('Quota release requires a database transaction');
        if($bytes<0)throw new RuntimeException('Invalid release bytes');
        $this->db->execute('UPDATE bf_media_usage SET used_bytes=GREATEST(0,used_bytes-?),original_count=GREATEST(0,original_count-?),variant_count=GREATEST(0,variant_count-?) WHERE scope_id=?',[$bytes,(int)$original,(int)!$original,$this->scopeId()]);
    }
    /** Central admin use only; caller must separately enforce platform authorization. */
    public function setTenantLimits(int $tenantId,int $maxBytes,int $maxFiles):void {
        if($this->context->current()->isTenant())throw new RuntimeException('Only central administration may configure tenant limits');
        if($tenantId<1||$maxBytes<1048576||$maxBytes>1099511627776||$maxFiles<1||$maxFiles>1000000)throw new RuntimeException('Invalid tenant media limits');
        if(!$this->db->row('SELECT id FROM tenants WHERE id=?',[$tenantId]))throw new RuntimeException('Tenant not found');
        $this->db->execute('INSERT INTO bf_media_quotas(scope_id,max_bytes,max_files) VALUES(?,?,?) ON DUPLICATE KEY UPDATE max_bytes=VALUES(max_bytes),max_files=VALUES(max_files)',[$tenantId,$maxBytes,$maxFiles]);
    }
}
