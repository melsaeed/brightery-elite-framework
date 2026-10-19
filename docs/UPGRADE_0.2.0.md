# Upgrade checklist: v0.1.0 -> v0.2.0

1. Stage the upgrade on a copy of the live database and files. Back up `.env`, `storage/`, `app/`, custom modules, language edits and any changes made directly in built-in themes.
2. Move customized framework themes to `app/themes/<matching path>.php`. Preserve all custom `app/overrides.php` code.
3. Extract `brightery-framework-v0.2.0-upgrade.zip` into the existing project root. Unlike the full package, it excludes unchanged files and all runtime state; **inspect the file list before applying**.
4. By default, tenancy remains `single` and does not require configuring domains. For multi mode, add `TENANCY_MODE=multi`, a valid `TENANCY_CONTROL_HOST`, and include that host in `TRUSTED_HOSTS`. Keep HTTPS and `SESSION_SECURE=true` in production.
5. Run:

```bash
php bin/build-assets
php bin/console module:validate
php bin/console migrate
php bin/console doctor
php tests/run.php
```

6. Restart PHP-FPM / reload OPcache. Confirm `/health`, `/admin/login`, `/admin`, translations and any custom modules work. Test old user authentication and media workflows with a staged MySQL database.
7. In multi mode, log in at the **central control host only**, open `/admin/tenants`, create tenant + exact domain mapping and configure its DNS/TLS. Only use tenant-aware services for tenant data; tenant-member auth is not yet supplied.

**Compatibility:** Container services are now type-checked when keyed by a class/interface. An application decorator replacing an interface implementation with a non-conforming class will fail explicitly. Previously edited framework themes may be overwritten by the upgrade; store your custom themes under `app/themes/`. The module JSON format is backward compatible for modules without explicit dependencies.

**Migrations:** `schema_migrations` records applied core/module scripts. MySQL DDL may partially commit even if a later statement fails; restore from backup/reconcile before retrying. For optional modules, enable first and re-run `migrate`. Disabled module data is preserved intentionally.
