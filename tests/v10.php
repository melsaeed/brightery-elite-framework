<?php
declare(strict_types=1);
use Brightery\Core\Compatibility\ContractInspector;
use Brightery\Core\Observability\{LoggerInterface,JsonLogger,RequestTelemetry,RequestSummary};
use Brightery\Core\Kernel;
use Brightery\Core\Http\Request;
check(is_a(JsonLogger::class,LoggerInterface::class,true),'custom logger contract is extendable');
check(version_compare(Kernel::VERSION,'1.0.0','>='),'v1.0 minimum version');
check(\Brightery\Core\Modules\ModuleManager::API_VERSION==='0.2.0','v1.0 preserves module API version');
$contract=(new ContractInspector())->check();
check($contract['compatible']&&$contract['checked_methods']>=20,'public extension API contract passes');
$corrupted=base_path('storage/cache/invalid-contract-'.bin2hex(random_bytes(6)).'.json');
try{
    $bad=json_decode((string)file_get_contents(base_path('resources/contracts/module-api-0.2.0.json')),true,16,JSON_THROW_ON_ERROR);
    $bad['contracts']['Brightery\\Core\\Container']['nonExistentMethod']=['required_max'=>0,'parameters_min'=>0,'static'=>false];
    file_put_contents($corrupted,json_encode($bad,JSON_THROW_ON_ERROR));
    check(!(new ContractInspector($corrupted))->check()['compatible'],'missing extension method fails compatibility');
}finally{@unlink($corrupted);}
$temp=base_path('storage/logs/test-v10-'.bin2hex(random_bytes(6)).'.jsonl');
try{
    $logger=new JsonLogger($temp);
    $logger->write('info','test.security',['api_token'=>'supersecret','nested'=>['password'=>'hunter2'],
        'path'=>'/reset-password/abcdefabcdefabcdef?code=verysecret']);
    $raw=(string)file_get_contents($temp);
    check(!str_contains($raw,'supersecret')&&!str_contains($raw,'hunter2')&&!str_contains($raw,'verysecret'),'structured logs redact secret context and token paths');
    check(str_contains($raw,'[REDACTED]')&&str_contains($raw,'[REDACTED-TOKEN]'),'structured log redactions are explicit');
    $telemetry=new RequestTelemetry($logger);
    $telemetry->finish('GET','/verify-email/abcdef0123456789?token=supersecret',200);
    $line=json_decode(trim(explode("\n",(string)file_get_contents($temp))[1]),true,32,JSON_THROW_ON_ERROR);
    check(preg_match('/^[a-f0-9]{32}$/D',(string)$line['context']['request_id'])===1,'correlation IDs use unpredictable fixed format');
    check(!str_contains((string)file_get_contents($temp),'supersecret'),'HTTP telemetry omits raw secret URLs');
    $telemetryError=new RequestTelemetry($logger);
    $telemetryError->finish('POST','/api/v1/profile',500,RuntimeException::class);
    $summary=RequestSummary::read($temp);
    check($summary['count']===2&&$summary['statuses']['2xx']===1&&$summary['statuses']['5xx']===1,'operational summary groups sanitized HTTP statuses');
    check($summary['p95_ms']!==null,'latency percentile is available');
    check(RequestSummary::read($temp,1)['count']===1,'bounded telemetry summary limits sample count');
}finally{@unlink($temp);}
check(RequestTelemetry::safePath('/invite/abcdef0123456789?key=secret')!=='/invite/abcdef0123456789?key=secret','sensitive routes are scrubbed');
$health=$kernel->handle(new Request('GET','/health',[],[],[],['HTTP_HOST'=>'localhost','HTTP_X_REQUEST_ID'=>'external-untrusted-value']));
check($health->status===200 && preg_match('/^[a-f0-9]{32}$/D',$health->headers['X-Request-ID']??'')===1,'HTTP request ID is server-generated');
check($health->headers['X-Request-ID']!=='external-untrusted-value','client-controlled request identifiers are ignored');
check((new ContractInspector())->check()['compatible'],'compatibility manifest remains unchanged after checks');
