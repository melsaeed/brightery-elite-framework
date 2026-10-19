# Upgrade Brightery Framework 1.3.0 → 1.4.0

1. Back up **all** project files (`.env`, `app/`, `storage/private/`, `app/themes/`, custom `modules/`) and MySQL databases; rehearse in a separate staging environment. Disable live traffic/jobs for migrations, not for a mere dry run.
2. Extract `brightery-framework-v1.4.0-upgrade.zip` at your **existing project root**. The archive is flat, relative to that root; never replace custom application modules. The release does not provide an automatic database rollback.
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

4. Restart PHP-FPM and supervised queue workers as appropriate after ensuring DB health, inspect protected CRUD pages, relation lookups and APIs with accounts from **different** tenants.
5. New module generation (1.4+) includes a custom `Policies/RecordPolicy.php`. Older generated modules work without any migration or source rewrite. Their existing 1.3 dropdown will not automatically be replaced until you opt to upgrade the application-owned module's controller/routes and view integration; the new core supports the functionality but intentionally does not overwrite custom code.
6. To evolve a module, use `/admin/developer/evolve` (or CLI): **backup → disable module → review additive SQL → create migration → `php bin/console module:migrate-disabled NAME --apply` → verify → enable module**. In production, the web apply action requires `MAINTENANCE_MODE=true` and `MODULE_BUILDER_MAINTENANCE=true`. External web traffic/workers must be stopped separately. New fields must be nullable and append-only.

There is **no new core database migration**. Public Module API remains `0.2.0`. The framework does not guarantee MySQL DDL rollback, enforce foreign keys, perform tenant-scoped table-level SQL permission grants, or certify module code as safe. Run the GitHub Actions integration and E2E checks before production deployment.
