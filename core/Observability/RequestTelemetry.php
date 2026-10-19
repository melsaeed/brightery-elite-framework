<?php
declare(strict_types=1);
namespace Brightery\Core\Observability;

/** Per-request correlation; never trusts incoming request ID, IP, URL queries, credentials, or request bodies. */
final class RequestTelemetry {
    public readonly string $id;
    private readonly int $started;
    public function __construct(private readonly LoggerInterface $logger){
        $this->id=bin2hex(random_bytes(16));
        $this->started=hrtime(true);
    }
    public static function safePath(string $path):string {
        $path=explode('?', $path,2)[0];
        $path=JsonLogger::cleanse($path);
        if(!is_string($path))return '[REDACTED]';
        return strlen($path)>200?substr($path,0,200).'[TRUNCATED]':$path;
    }
    public function finish(string $method,string $path,int $status,?string $exceptionClass=null):void {
        $data=['request_id'=>$this->id,'method'=>in_array($method,['GET','HEAD','POST','PUT','PATCH','DELETE'],true)?$method:'OTHER',
            'path'=>self::safePath($path),'status'=>$status,
            'duration_ms'=>round((hrtime(true)-$this->started)/1_000_000,3)];
        if($exceptionClass!==null)$data['exception_class']=$exceptionClass;
        $this->logger->write($status>=500?'error':'info','http.complete',$data);
    }
}
