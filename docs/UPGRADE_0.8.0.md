# Upgrade Brightery Framework v0.7.0 → v0.8.0

v0.8.0 adds signed module lifecycle operations without changing database schema. Public Module API remains `0.2.0`; System module version remains `0.3.4`.

1. Back up **MySQL and storage/private together**, your application overrides/themes, and the entire project. Capture the enabled module state (`storage/private/modules.json`), queue/scheduler status and any custom module modifications. Test on staging first.
2. Stop queue workers and the scheduler; enable web-tier maintenance. Extract `brightery-framework-v0.8.0-upgrade.zip` in the current project root. It contains only added/modified framework-owned files. Never overwrite `.env`, `storage/`, app themes or app overrides.
3. Merge `.env.example` changes manually. New option `MODULE_MAINTENANCE=false`; for production signed module `--apply` actions, use `true` **only during a real site maintenance window** and also stop traffic outside PHP.
4. Run the preflight and existing tests:

```bash
php bin/build-assets
php bin/console doctor
php bin/console module:validate
php tests/run.php
php bin/console migrate:status
```

5. v0.8 has **no new core migrations**. Do not run destructive changes in a maintenance window without reviewing pending migrations from previous versions or custom modules. Check custom modules and DI overrides with staging and regenerate frontend assets.
6. Review [SIGNED_MODULES.md](SIGNED_MODULES.md). `.bfp` packages are signed JSON bundles, not ZIPs. Trust a public key explicitly, verify and dry-run before installing. Never activate unreviewed third-party modules; code signatures are publisher-authenticity controls, not sandboxing.
7. After staging tests and deployment verification, restart PHP-FPM, queue workers and scheduler; verify administrator login, tenant isolation, module activation/disable, and tenant-specific media routes. Monitor errors.

## Reverting v0.8 framework code

No v0.8 database migration exists, but rolling back the framework itself must use a tested copy of the entire previous project, `APP_KEY`, tenant data, queue schema and private media from the same checkpoint. Modules installed with v0.8 may depend on APIs/features unavailable to v0.7; disable/revert them **before** restoring v0.7. A module file rollback with `module:rollback` is separate from a full framework rollback and never reverses SQL migrations.

## Verification limits

CI/unit tests cover Ed25519 signature trust/tamper, path traversal, module preflight, dry-run, upgrade backup, and explicit file rollback. The build environment still lacks `pdo_mysql` and GD; cross-tenant database concurrency, image processing, SMTP and external penetration testing remain production gates.
