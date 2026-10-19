# Brightery Framework 1.5.0 → 1.6.0

This is an **additive core upgrade**. Backup and rehearse in staging before deploying to existing PHP-FPM/Nginx systems. The upgrade ZIP contains paths relative to your **framework project root**; the full ZIP has a top-level `brightery-framework-v1.6.0/` folder.

1. Back up application files, MySQL and modules. Stop background workers and enter maintenance mode for database migrations. Verify `pdo_mysql`, PHP 8.4+, and your real MySQL 8 service.
2. Inspect the upgrade ZIP then extract the **upgrade ZIP**, not the full ZIP, into the v1.5.0 project root, overwriting only framework-owned files. Keep `.env`, `app/`, application `modules/`, custom themes, and `storage/` untouched.
3. Run (from project root):

```bash
php bin/build-assets
php bin/console compat:check
php bin/console module:validate
php bin/console migrate:status
php tests/run.php
php tests/security.php
php bin/console deploy:preflight --json
```

4. Smoke test global/tenant sign-ins, permissions, CRUD, relation lookups, and generated APIs. Run MySQL disposable integration tests and browser tests **before production use**. Restart PHP-FPM/worker processes after release validation.

## Generating a new module with many-to-many fields

Use a target generated module in the same scope, already installed and migrated. New module blueprints can include `{"name":"tags","label":"Tags","type":"many_to_many","references":"Tags","required":false}`. Use `/admin/developer` or the preview-by-default `make:module` CLI to review its FK-backed join-table SQL. Generate under a controlled maintenance setting. Explicitly enable the new module and apply migrations separately. Its dependency on the target module is included in `module.json`.

## Adding relationships to an existing generated module

**Back up first.** Disable the module, append an optional many-to-many field without touching existing fields, preview via `evolve:module`, review SQL and confirm using `--apply`, then run `module:migrate-disabled MODULE --apply` during maintenance. After validation, re-enable. The evolver changes the application-owned blueprint and creates a new migration but deliberately does not modify existing custom PHP or undo old data. If the target module is not enabled/migrated, coordinate its deployment before the new join-table migration.

## Compatibility, rollback and limits

The public Module API stays `0.2.0`, System module stays `0.3.5`; no new core migration. Old module blueprints and controllers stay valid. Downgrading PHP files is **not** enough to reverse an applied application join-table migration: restore database backup or create and review a separate forward migration. The CSV export and bulk-restore endpoints are present only on **newly generated** modules until you manually merge them into old app-owned controllers/routes. Run staging tests on actual MySQL before relying on tenant isolation and SQL savepoints.
