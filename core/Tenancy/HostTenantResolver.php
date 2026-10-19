<?php
declare(strict_types=1);
namespace Brightery\Core\Tenancy;
use Brightery\Core\Contracts\TenantResolverInterface;
use Brightery\Core\Database;
use Brightery\Core\Http\Request;
use InvalidArgumentException;

/** No implicit tenant fallback: unknown, suspended or deleted tenant hosts never resolve. */
final class HostTenantResolver implements TenantResolverInterface {
    public function __construct(private readonly Database $database){}
    public function resolve(Request $request):?TenantContext {
        $host=self::hostname($request->server['HTTP_HOST']??'');
        $mode=strtolower((string)env('TENANCY_MODE','single'));
        if($mode==='single')return new TenantContext('single',null,null,$host);
        if($mode!=='multi')throw new InvalidArgumentException('TENANCY_MODE must be single or multi');
        $central=self::hostname((string)env('TENANCY_CONTROL_HOST',''));
        if($central===''||$central==='localhost')throw new InvalidArgumentException('Configure TENANCY_CONTROL_HOST for multi-tenant mode');
        if($host===$central)return new TenantContext('central',null,null,$host);
        $row=$this->database->row("SELECT t.id,t.slug FROM tenant_domains d JOIN tenants t ON t.id=d.tenant_id WHERE d.hostname=? AND t.status='active' LIMIT 1",[$host]);
        return $row?new TenantContext('tenant',(int)$row['id'],(string)$row['slug'],$host):null;
    }
    /** Port stripping is allowed only for syntactically valid host:port values. */
    public static function hostname(string $value):string {
        $value=strtolower(trim($value));
        if(!preg_match('/^([a-z0-9][a-z0-9.-]{0,251}[a-z0-9]|[a-z0-9])(?::[0-9]{1,5})?$/D',$value))return '';
        $host=preg_replace('/:[0-9]{1,5}$/','',$value);
        if(str_contains($host,'..')||str_starts_with($host,'.')||str_ends_with($host,'.'))return '';
        return $host;
    }
}
