<?php
declare(strict_types=1);
namespace Brightery\Core\Contracts;
use Brightery\Core\Container;
use Brightery\Core\Events\Hooks;

/** Stable extension contract: register all services before booting providers. */
interface ServiceProviderInterface {
    public function register(Container $container):void;
    public function boot(Container $container,Hooks $hooks):void;
}
