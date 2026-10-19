<?php
declare(strict_types=1);
namespace Brightery\Core\Media\Scan;
/** Throw on malware OR scanner errors (fail-closed when enabled). */
interface ScannerInterface { public function scan(string $path):void; }
