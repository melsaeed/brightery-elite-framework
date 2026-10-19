# Upgrade Brightery Framework 1.2.0 → 1.3.0

1. Back up the full project (`.env`, `app/`, application modules and themes, private `storage/`) and database. Rehearse on staging.
2. Extract `brightery-framework-v1.3.0-upgrade.zip` into the existing **project root**; files inside are relative, not nested under a release directory. Never replace `app/` or application-owned `modules/<Name>` directories with release files.
3. Run `php bin/build-assets`, `php bin/console compat:check`, `php bin/console module:validate`, `php tests/run.php`, `php tests/security.php` and `php bin/console deploy:preflight --json`.
4. For generated modules, use `php bin/console evolve:module NAME BLUEPRINT.json` to review an optional nullable column addition. New schema files are **not** created automatically during the framework upgrade. Use `php bin/console module:migrate-disabled NAME --apply` after evolution and before reactivation. See [Module Builder 1.3](MODULE_BUILDER_1.3.md).
5. Do not enable a module with a newly published blueprint until its new migration has applied successfully. Confirm tenant isolation and API access with real accounts.
6. Reload PHP-FPM, verify Nginx/Cloudflare caching of protected responses, and check logs and operations dashboard.

**Compatibility:** Public Module API stays `0.2.0`. Existing 1.2 modules and their content are left in place. New generated modules require release `>=1.3.0` and can use declared `relation` types; existing modules need no code changes. The framework requires PHP 8.4+. The release has no core schema migration.

**Not a security certification:** MySQL integration/concurrency, image handling, browser interaction, and production load testing still require execution in a correctly provisioned environment.
