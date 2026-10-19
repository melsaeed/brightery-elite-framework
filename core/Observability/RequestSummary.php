<?php
declare(strict_types=1);
namespace Brightery\Core\Observability;

/** Read-only bounded log summary: minimum telemetry for diagnosing p95 without publishing secrets. */
final class RequestSummary {
    public static function read(string $filename,int $limit=2000):array {
        if($limit<1||$limit>10000)throw new \InvalidArgumentException('Limit must be 1..10000');
        $durations=[];$statuses=[];$total=0;
        if(!is_file($filename)||is_link($filename))return self::result([],[],0);
        $handle=fopen($filename,'rb');if($handle===false)return self::result([],[],0);
        try {
            $size=fstat($handle)['size']??0;
            $start=max(0,$size-8*1024*1024);
            fseek($handle,$start);
            if($start>0)fgets($handle); // discard partial entry
            $samples=[];
            while(!feof($handle)){
                $line=fgets($handle,32768);if($line===false)break;
                if(strlen($line)>16384)continue;
                $event=json_decode($line,true);
                if(($event['event']??null)!=='http.complete')continue;
                $context=$event['context']??null;
                if(!is_array($context)||!is_numeric($context['duration_ms']??null)||!is_int($context['status']??null))continue;
                $samples[]=['ms'=>max(0,min(120000,(float)$context['duration_ms'])),'status'=>(int)$context['status']];
                if(count($samples)>$limit)array_shift($samples);
            }
            foreach($samples as $sample){$durations[]=$sample['ms'];$bucket=intdiv($sample['status'],100).'xx';$statuses[$bucket]=($statuses[$bucket]??0)+1;$total++;}
        }finally{fclose($handle);}
        return self::result($durations,$statuses,$total);
    }
    private static function result(array $durations,array $statuses,int $total):array {
        sort($durations,SORT_NUMERIC);ksort($statuses);
        $percentile=static function(float $fraction)use($durations):?float {
            $n=count($durations);return $n===0?null:round($durations[max(0,(int)ceil($n*$fraction)-1)],2);
        };
        return ['count'=>$total,'statuses'=>$statuses,'p50_ms'=>$percentile(0.50),'p95_ms'=>$percentile(0.95),'p99_ms'=>$percentile(0.99)];
    }
}
