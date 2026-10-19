<?php
declare(strict_types=1);
namespace Brightery\Core\Media;
use Brightery\Core\Database;
use RuntimeException;
/** Upgrade-safe service: register your own implementation in app/overrides.php. */
class ImageVariants {
    public function __construct(protected readonly Database $db, protected readonly PrivateMedia $media,protected readonly MediaQuota $quota){}
    /** Zero-trust dimensions and strict image formats; reject oversized decompression before decoding. */
    public static function dimensions(int $sourceWidth,int $sourceHeight,int $width,int $height, int $bytes):void {
        if($sourceWidth<1||$sourceHeight<1||$sourceWidth*$sourceHeight>20000000||$sourceWidth>10000||$sourceHeight>10000)throw new RuntimeException('Source image exceeds processing limits');
        if($width<16||$height<16||$width>2400||$height>2400||$width*$height>4000000)throw new RuntimeException('Output dimensions exceed processing limits');
        if($bytes<1||$bytes>10485760)throw new RuntimeException('Source file too large');
    }
    public static function mode(string $mode):string {
        if(!in_array($mode,['cover','contain'],true))throw new RuntimeException('Invalid crop mode');return $mode;
    }
    public function create(int $mediaId,int $width,int $height,string $mode='cover'):int {
        $mode=self::mode($mode);
        $row=$this->media->metadata($mediaId);
        if(!$row||!in_array($row['mime'],['image/jpeg','image/png','image/webp'],true))throw new RuntimeException('Source image unavailable');
        $tenant=$this->media->scope();
        $existing=$tenant===null?$this->db->row('SELECT id,storage_key FROM bf_media_variants WHERE media_id=? AND tenant_id IS NULL AND width=? AND height=? AND fit_mode=?',[$mediaId,$width,$height,$mode]):$this->db->row('SELECT id,storage_key FROM bf_media_variants WHERE media_id=? AND tenant_id=? AND width=? AND height=? AND fit_mode=?',[$mediaId,$tenant,$width,$height,$mode]);
        if($existing){
            if(is_file(base_path('storage/private/media/'.$existing['storage_key'])))return (int)$existing['id'];
            $this->delete((int)$existing['id']);
        }
        $src=base_path('storage/private/media/'.$row['storage_key']);
        if(!is_file($src)||is_link($src))throw new RuntimeException('Source file unavailable');
        $info=@getimagesize($src);if($info===false)throw new RuntimeException('Invalid source image');
        self::dimensions((int)$info[0],(int)$info[1],$width,$height,(int)$row['bytes']);
        if(!extension_loaded('gd')||!function_exists('imagejpeg'))throw new RuntimeException('Image processing requires GD JPEG encoding');
        $loader=match($row['mime']){'image/jpeg'=>'imagecreatefromjpeg','image/png'=>'imagecreatefrompng','image/webp'=>'imagecreatefromwebp'};
        if(!function_exists($loader))throw new RuntimeException('Image codec unavailable');
        $image=@$loader($src);if(!$image)throw new RuntimeException('Unable to decode image');
        $target=imagecreatetruecolor($width,$height);
        try {
            $white=imagecolorallocate($target,255,255,255);imagefill($target,0,0,$white);
            $sw=(int)$info[0];$sh=(int)$info[1];
            if($mode==='cover'){
                $factor=max($width/$sw,$height/$sh);
                $cropWidth=min($sw,$width/$factor);$cropHeight=min($sh,$height/$factor);
                $sourceX=($sw-$cropWidth)/2;$sourceY=($sh-$cropHeight)/2;
                imagecopyresampled($target,$image,0,0,(int)round($sourceX),(int)round($sourceY),$width,$height,(int)round($cropWidth),(int)round($cropHeight));
            }else{
                $factor=min(1,$width/$sw,$height/$sh);
                $dw=max(1,(int)round($sw*$factor));$dh=max(1,(int)round($sh*$factor));
                imagecopyresampled($target,$image,(int)floor(($width-$dw)/2),(int)floor(($height-$dh)/2),0,0,$dw,$dh,$sw,$sh);
            }
            $directory=base_path('storage/private/media');$key=bin2hex(random_bytes(32));$dest=$directory.'/'.$key;
            if(!imagejpeg($target,$dest,86))throw new RuntimeException('Image encode failed');
        }finally{imagedestroy($target);imagedestroy($image);}
        @chmod($dest,0600);
        try {
            return $this->db->transactional(function(Database $db)use($mediaId,$tenant,$key,$dest,$width,$height,$mode):int {
                // Row lock coordinates with parent deletion; tenant scope checked within the lock.
                $parent=$tenant===null
                    ?$db->row('SELECT id FROM bf_private_media WHERE id=? AND tenant_id IS NULL FOR UPDATE',[$mediaId])
                    :$db->row('SELECT id FROM bf_private_media WHERE id=? AND tenant_id=? FOR UPDATE',[$mediaId,$tenant]);
                if(!$parent)throw new RuntimeException('Source image no longer exists');
                $size=(int)filesize($dest);
                $this->quota->reserve($size,false);
                $db->execute('INSERT INTO bf_media_variants(media_id,tenant_id,storage_key,mime,bytes,width,height,fit_mode) VALUES(?,?,?,?,?,?,?,?)',[$mediaId,$tenant,$key,'image/jpeg',$size,$width,$height,$mode]);
                return (int)$db->pdo()->lastInsertId();
            });
        }catch(\Throwable $e){
            @unlink($dest);
            // Duplicate dimensions may be produced by concurrent workers; return the scoped winner.
            $winner=$tenant===null
                ?$this->db->row('SELECT id FROM bf_media_variants WHERE media_id=? AND tenant_id IS NULL AND width=? AND height=? AND fit_mode=?',[$mediaId,$width,$height,$mode])
                :$this->db->row('SELECT id FROM bf_media_variants WHERE media_id=? AND tenant_id=? AND width=? AND height=? AND fit_mode=?',[$mediaId,$tenant,$width,$height,$mode]);
            if($winner)return (int)$winner['id'];
            throw $e;
        }
    }
    public function list(int $mediaId):array {
        if(!$this->media->metadata($mediaId))return [];
        $tenant=$this->media->scope();
        return $tenant===null?$this->db->rows('SELECT id,width,height,fit_mode,mime,bytes FROM bf_media_variants WHERE media_id=? AND tenant_id IS NULL ORDER BY id DESC LIMIT 100',[$mediaId])
            :$this->db->rows('SELECT id,width,height,fit_mode,mime,bytes FROM bf_media_variants WHERE media_id=? AND tenant_id=? ORDER BY id DESC LIMIT 100',[$mediaId,$tenant]);
    }
    public function read(int $variantId):?array {
        $tenant=$this->media->scope();
        $row=$tenant===null?$this->db->row('SELECT v.*,m.original_name FROM bf_media_variants v JOIN bf_private_media m ON m.id=v.media_id WHERE v.id=? AND v.tenant_id IS NULL AND m.tenant_id IS NULL',[$variantId])
            :$this->db->row('SELECT v.*,m.original_name FROM bf_media_variants v JOIN bf_private_media m ON m.id=v.media_id WHERE v.id=? AND v.tenant_id=? AND m.tenant_id=?',[$variantId,$tenant,$tenant]);
        if(!$row)return null;
        $file=base_path('storage/private/media/'.$row['storage_key']);if(!is_file($file)||is_link($file))return null;
        $bytes=file_get_contents($file);if($bytes===false)return null;
        return ['name'=>pathinfo($row['original_name'],PATHINFO_FILENAME).'-'.$row['width'].'x'.$row['height'].'.jpg','mime'=>'image/jpeg','bytes'=>$bytes];
    }
    public function delete(int $variantId):bool {
        if($variantId<1)return false;
        $tenant=$this->media->scope();$key=null;
        $deleted=$this->db->transactional(function(Database $db)use($variantId,$tenant,&$key):bool {
            $row=$tenant===null
                ?$db->row('SELECT storage_key,bytes FROM bf_media_variants WHERE id=? AND tenant_id IS NULL FOR UPDATE',[$variantId])
                :$db->row('SELECT storage_key,bytes FROM bf_media_variants WHERE id=? AND tenant_id=? FOR UPDATE',[$variantId,$tenant]);
            if(!$row)return false;
            $n=$tenant===null?$db->execute('DELETE FROM bf_media_variants WHERE id=? AND tenant_id IS NULL',[$variantId])
                :$db->execute('DELETE FROM bf_media_variants WHERE id=? AND tenant_id=?',[$variantId,$tenant]);
            if($n!==1)return false;
            $this->quota->release((int)$row['bytes'],false);
            $key=(string)$row['storage_key'];return true;
        });
        if($deleted&&$key!==null)@unlink(base_path('storage/private/media/'.$key));
        return $deleted;
    }
}
