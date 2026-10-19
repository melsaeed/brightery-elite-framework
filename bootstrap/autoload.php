<?php
declare(strict_types=1);
define('BASE_PATH', dirname(__DIR__));
$autoload = BASE_PATH . '/vendor/autoload.php';
if (is_file($autoload)) { require_once $autoload; }
else {
    spl_autoload_register(static function (string $class): void {
        $prefixes = ['Brightery\\Core\\' => 'core/', 'App\\' => 'app/', 'Modules\\' => 'modules/'];
        foreach ($prefixes as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) continue;
            $relative = substr($class, strlen($prefix));
            $file = BASE_PATH . '/' . $directory . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) require_once $file;
            return;
        }
    });
}
if (is_file(BASE_PATH . '/.env')) {
    foreach (file(BASE_PATH . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key) || getenv($key) !== false) continue;
        $value = trim(trim($value), "\"'");
        putenv("$key=$value"); $_ENV[$key] = $value;
    }
}
function env(string $name, mixed $default = null): mixed {
    $value = getenv($name);
    return $value === false ? $default : $value;
}
function esc(mixed $value): string {return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');}
function base_path(string $path = ''): string { return BASE_PATH . ($path !== '' ? '/' . ltrim($path, '/') : ''); }
