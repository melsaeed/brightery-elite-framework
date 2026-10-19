<?php
declare(strict_types=1);
namespace Modules\TenantNotes;
use Brightery\Core\Container;
use Brightery\Core\Contracts\ServiceProviderInterface;
use Brightery\Core\Events\Hooks;
use Brightery\Core\Tenancy\TenantRecords;

/** Example provider: service is usable only in requests with a resolved tenant. */
final class NotesServiceProvider implements ServiceProviderInterface {
    public function register(Container $container):void {
        $container->bind(NoteRepository::class,static fn(Container $c)=>new NoteRepository($c->get(TenantRecords::class)));
    }
    public function boot(Container $container,Hooks $hooks):void {
        $hooks->listen('http.request',static function():void {});
    }
}
