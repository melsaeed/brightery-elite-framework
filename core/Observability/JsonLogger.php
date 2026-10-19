<?php
declare(strict_types=1);
namespace Brightery\Core\Observability;

use RuntimeException;

/** Append-only, structured, bounded JSON lines; deliberately never serializes request bodies or exception messages. */
final class JsonLogger implements LoggerInterface {
    private const LEVELS=['debug'=>0,'info'=>1,'notice'=>2,'warning'=>3,'error'=>4,'critical'=>5];
    public function __construct(private readonly string $filename,private readonly string $minimum='info') {
        if(!isset(self::LEVELS[$minimum]))throw new RuntimeException('Invalid logging level');
    }
    public static function cleanse(mixed $value,int $depth=0):mixed {
        if($depth>4)return '[TRUNCATED]';
        if(is_string($value)){
            $value=preg_replace('~(/(?:invite|reset-password|verify-email)/)[^/?\s]{8,}(?:\?[^\s]*)?~i','$1[REDACTED-TOKEN]',$value)??'[REDACTED]';
            if(str_starts_with($value,'/'))$value=explode('?', $value,2)[0];
            $value=preg_replace('~\bBearer\s+[^\s]+~i','Bearer [REDACTED]',$value)??'[REDACTED]';
            // No URLs with query strings: access credentials may live in them.
            $value=preg_replace('~https?://[^\s]+~i','[URL-REDACTED]',$value)??'[REDACTED]';
            return strlen($value)>512?substr($value,0,512).'[TRUNCATED]':$value;
        }
        if(is_bool($value)||is_int($value)||is_float($value)||$value===null)return $value;
        if(!is_array($value))return '[UNSERIALIZABLE]';
        $clean=[]; $count=0;
        foreach($value as $key=>$item){
            if(++$count>40){$clean['_truncated']='[TRUNCATED]';break;}
            $key=(string)$key;
            if(preg_match('/password|secret|token|api.?key|authorization|cookie|credential|session|nonce|private.?key|payload|body/i',$key)){
                $clean[$key]='[REDACTED]';continue;
            }
            $clean[substr($key,0,80)]=self::cleanse($item,$depth+1);
        }
        return $clean;
    }
    public function write(string $level,string $event,array $context=[]):void {
        if(!isset(self::LEVELS[$level]))throw new RuntimeException('Invalid logging level');
        if(self::LEVELS[$level]<self::LEVELS[$this->minimum])return;
        if(!preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,79}$/D',$event))throw new RuntimeException('Invalid event name');
        $entry=['ts'=>gmdate('Y-m-d\TH:i:s\Z'),'level'=>$level,'event'=>$event,'context'=>self::cleanse($context)];
        $encoded=json_encode($entry,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)."\n";
        $directory=dirname($this->filename);
        if(!is_dir($directory))throw new RuntimeException('Logging directory does not exist');
        if(is_link($this->filename))throw new RuntimeException('Refusing symlinked log file');
        if(file_put_contents($this->filename,$encoded,FILE_APPEND|LOCK_EX)===false)throw new RuntimeException('Cannot write structured log');
        @chmod($this->filename,0600);
    }
}
