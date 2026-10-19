<?php
declare(strict_types=1);
namespace Brightery\Core\Observability;
/** Upgrade-safe structured logger contract. Override in app/overrides.php through DI. */
interface LoggerInterface {
    public function write(string $level,string $event,array $context=[]):void;
}
