<?php
declare(strict_types=1);
require_once __DIR__.'/autoload.php';
date_default_timezone_set((string)env('APP_TIMEZONE','UTC'));
return new \Brightery\Core\Kernel();
