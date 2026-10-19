<?php
declare(strict_types=1);
namespace Brightery\Core;
final class App {
    private static ?Kernel $kernel=null;
    public static function set(Kernel $kernel): void {self::$kernel=$kernel;}
    public static function get(): Kernel {return self::$kernel??throw new \RuntimeException('Application not booted');}
}
