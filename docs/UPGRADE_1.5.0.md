# Upgrade: v1.4.0 → v1.5.0

**Upgrade-safe scope.** This pack contains framework-owned changes only. Back up the entire installation and database first; test on an isolated staging instance. Preserve `.env`, `app/`, `modules/<application modules>/`, custom themes, and `storage/`. Do not extract any ZIP containing a top-level wrapping directory directly into a different production root without stripping the wrapper appropriately.

1. Enable maintenance mode and stop workers for staging. Review the v1.5.0 change log.
2. Extract the update archive into the framework project root, replacing framework-owned files only; verify ownership and permissions.
3. Run:

```bash
php bin/build-assets
php bin/console compat:check
php bin/console module:validate
php bin/console migrate:status
php tests/run.php
php tests/security.php
php bin/console deploy:preflight --json
```

4. Validate deployed site, logins, roles, central/tenant routes, and existing generated modules. Restart PHP-FPM and background workers after testing.
5. Only enable `soft_delete` on **newly generated modules**. Existing modules are not silently migrated or rewritten. There are no new framework-owned MySQL migrations in v1.5.0.

**Rollback:** restore a backed-up v1.4.0 source tree if needed; do not roll back generated application database schemas automatically. New modules created with v1.5.0 may require manual migration or removal before downgrading. Framework module API stays at `0.2.0`.

**Required staging gate:** run the existing disposable MySQL 8 integration suite (including the v1.5.0 soft-delete isolation cases) and Chromium interaction tests, then evaluate production-like performance and security. No such checks are marked as locally passed without execution.
