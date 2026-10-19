<?php
declare(strict_types=1);
namespace Brightery\Core\Media\Scan;
use RuntimeException;
/** clamd INSTREAM over a local UNIX socket, bounded streaming and fail-closed verdict. */
final class ClamAvScanner implements ScannerInterface {
    public function __construct(private readonly string $socket='/run/clamav/clamd.ctl'){}
    public static function verdict(string $response):void {
        if(rtrim($response,"\0\r\n")==='stream: OK')return;
        if(str_contains($response,'FOUND'))throw new RuntimeException('Malware detected');
        throw new RuntimeException('Antivirus scan failed or returned an unknown verdict');
    }
    public function scan(string $path):void {
        if(!is_file($path)||is_link($path))throw new RuntimeException('Invalid scan target');
        if(!str_starts_with($this->socket,'/')||str_contains($this->socket,"\0"))throw new RuntimeException('Invalid clamd socket');
        $stream=@stream_socket_client('unix://'.$this->socket,$errno,$errstr,5,STREAM_CLIENT_CONNECT);
        if(!$stream)throw new RuntimeException('Antivirus service unavailable');
        stream_set_timeout($stream,10);
        $handle=@fopen($path,'rb');if(!$handle){fclose($stream);throw new RuntimeException('Cannot read scan target');}
        try {
            if(fwrite($stream,"zINSTREAM\0")!==10)throw new RuntimeException('Scan transport failed');
            $total=0;
            while(!feof($handle)){
                $chunk=fread($handle,65536);
                if($chunk===false)throw new RuntimeException('Scan read failed');
                if($chunk==='')throw new RuntimeException('Scan read interrupted');
                $length=strlen($chunk);$total+=$length;
                if($total>10485760)throw new RuntimeException('Scan size limit exceeded');
                $buffer=pack('N',$length).$chunk;
                $sent=0;while($sent<strlen($buffer)){
                    $n=fwrite($stream,substr($buffer,$sent));if($n===false||$n===0)throw new RuntimeException('Scan transport failed');$sent+=$n;
                }
            }
            if(fwrite($stream,pack('N',0))!==4)throw new RuntimeException('Scan transport failed');
            $reply=fgets($stream,4096);$state=stream_get_meta_data($stream);
            if($reply===false||($state['timed_out']??false))throw new RuntimeException('Antivirus scan timed out');
            self::verdict($reply);
        }finally{fclose($handle);fclose($stream);}
    }
}
