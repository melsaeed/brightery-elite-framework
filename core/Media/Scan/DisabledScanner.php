<?php
declare(strict_types=1);
namespace Brightery\Core\Media\Scan;
use RuntimeException;
/** Explicit, conspicuous opt-out. Not a substitute for antivirus. */
final class DisabledScanner implements ScannerInterface {
    public function scan(string $path):void {
        if(filter_var(env('MEDIA_SCAN_REQUIRED','false'),FILTER_VALIDATE_BOOLEAN))throw new RuntimeException('Antivirus required but no scanner is configured');
    }
}
