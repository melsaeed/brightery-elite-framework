# Brightery Framework 1.0 — Extension Compatibility Policy

**Framework release:** 1.0.0. **Public Module API:** 0.2.0 (unchanged). A framework's release number and its extension API are separate. The 1.0.0 label is not a promise that every internal PHP class is permanently frozen.

## Stable surfaces

`resources/contracts/module-api-0.2.0.json` is the executable minimum public-API contract. `php bin/console compat:check` checks callable public methods, required parameter counts, arity and static/instance semantics and verifies the module dependency DAG. CI blocks updates violating this minimum contract. PHP argument/return type compatibility, behavior, security and runtime compatibility need additional manual and integration review; this checker **cannot prove semantic equivalence**.

The supported extension surfaces are:

- `ServiceProviderInterface` (`register`, `boot`) loaded through `module.json`.
- `Container::bind`, `scoped`, `instance`, `alias`, `extend`, `get`, `has`, `resetScope`.
- `Hooks::listen`, `emit`, `filter`, `apply`.
- `ModuleManager` discovery, activation, ordering and route hooks.
- `IslandRenderer` registration for server-rendered progressive UI.
- `LoggerInterface` for structured observability replacement.

App-owned changes belong in `app/overrides.php`, `app/themes` or app-owned modules. **Do not edit `core/`, `themes/` or the built-in `modules/System`**. Modules must use declared dependencies and supported hooks; direct use of internal database implementation methods may be necessary today but is not covered by the guaranteed method manifest.

## Custom logger example

In `app/overrides.php` (merge into an existing closure):

```php
return static function (\Brightery\Core\Container $container): void {
    $container->bind(
        \Brightery\Core\Observability\LoggerInterface::class,
        \App\Monitoring\CustomLogger::class,
    );
};
```

`CustomLogger` must implement `write(string $level, string $event, array $context=[]): void` and must not persist secrets. The application is responsible for log transport, failure handling, and retention.

## Release policy

1. Deprecate before removing supported methods and provide a migration guide.
2. Any unavoidable incompatible surface change requires a **new Module API version**, not merely a framework patch.
3. Test currently installed third-party modules on a staging clone, including their own migrations and isolation rules. Signed modules authenticate authorship, **not security**.
4. Module package installation is dry-run-first; database migrations are additive and are not undone by file rollback.
5. Never install an unreviewed package on a production tenant environment.
