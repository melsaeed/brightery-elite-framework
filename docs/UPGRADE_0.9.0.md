# Upgrade Brightery Framework 0.8.0 → 0.9.0

Release 0.9.0 introduces worker heartbeat persistence, operator diagnostics, guarded release finalization and tenant-scope media counter reconciliation. The only new core migration is `008_operations_reliability.sql`. **Public module API stays at 0.2.0; System module remains at 0.3.4**. No existing application module or theme must be rewritten.

1. On staging, create a complete, restorable checkpoint: site files, enabled modules, database, private media, configuration and signing keys. Record running queues and active sessions.
2. Drain request traffic, queue workers and scheduled jobs **outside** PHP. Extract the v0.9.0 upgrade ZIP at the installed project root, replacing only the framework-owned paths inside it. `.env`, `app/`, application-installed modules, custom themes and `storage/` are excluded from the upgrade pack.
3. Merge new settings from `.env.example` into `.env` manually. Defaults are `DEPLOY_MAINTENANCE=false` and `MEDIA_RECONCILE_MAINTENANCE=false`.
4. Execute on staging:

```bash
php bin/build-assets
php bin/console module:validate
php tests/run.php
php bin/console migrate:status
php bin/console deploy:preflight --json
php bin/console deploy:finalize
```

5. After backup and operator review, enable `DEPLOY_MAINTENANCE=true` and run `php bin/console deploy:finalize --apply` in a controlled maintenance window. Or use `php bin/console migrate` manually if your deployment system handles migrations. Do not run both concurrently.
6. Disable maintenance flags, reload PHP-FPM and restart workers. Run `php bin/console ops:status`, inspect Admin > Operations, verify tenant media/permissions/admin login, confirm scheduler and worker health. Never turn on automatic media counter repair until you have investigated drift.

**Rollback:** Code rollback needs a matched checkpoint of DB/schema/private storage; MySQL DDL cannot be reversed automatically and the new operational services must not run against an old schema. Release marker files alone are not backups or a rollback mechanism.

**Verification limits:** Local PHP unit/smoke and HTTP smoke tests are not substitutes for live MySQL, concurrent queue lease handling, external system delivery, security or performance testing.
