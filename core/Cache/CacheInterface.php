<?php
declare(strict_types=1);
namespace Brightery\Core\Cache;
interface CacheInterface {public function get(string $key,mixed $default=null): mixed; public function put(string $key,mixed $value,int $ttl=300): void;public function forget(string $key): void;public function remember(string $key,int $ttl,callable $callback): mixed;}
