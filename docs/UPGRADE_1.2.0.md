# Upgrade Brightery Framework 1.1.0 → 1.2.0

1. Back up the **entire application**, including `.env`, MySQL, `app/`, `storage/`, and all application-owned folders under `modules/`. Test the upgrade on staging. The upgrade ZIP contains only files added/changed by v1.2.0 relative to the stock v1.1.0 release; no deletion instructions, and it does not include private data or any new custom modules.
2. Extract the v1.2.0 upgrade ZIP **into the existing framework root** (it contains relative paths), not over an unbacked-up production deployment.
3. Rebuild fingerprinted CSS and JavaScript: `php bin/build-assets`.
4. Run `php bin/console compat:check`, `php bin/console module:validate`, `php bin/console migrate:status`, `php tests/run.php`, `php tests/security.php`, and `php bin/console deploy:preflight --json`. v1.2.0 itself adds no automatic schema migration.
5. Restart PHP-FPM and workers after verification. Confirm authenticated Admin → Module Builder and existing Form Studio/Modules pages.
6. For generated modules: preview/apply individually and review generated SQL before activating each module and running migrations. `MODULE_BUILDER_MAINTENANCE=true` is required for web writes in production; CLI production writes use `MAINTENANCE_MODE=true`.

**Compatibility:** Kernel version becomes 1.2.0 while the public Module API stays `0.2.0`. Modules targeting the documented 0.2 API remain compatible. Existing module versions and application overrides remain unchanged. API token scopes add `modules:read` and `modules:write`, but no existing token receives them implicitly.

**Remaining release gate:** Disposable MySQL 8 integration suite and race tests, real browser interaction, end-to-end infrastructure security and capacity testing have not run in the authoring environment. Do not declare production readiness solely from local smoke checks.
