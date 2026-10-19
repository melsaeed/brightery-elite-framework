# Brightery Framework

**Version 1.6.1 — production-hardening candidate** · PHP 8.4+ · MySQL 8 · modular MVC · hybrid PHP/JavaScript · optional multi-tenancy.

> **Security status:** Not independently certified as production-safe. The source now includes fail-closed production configuration validation and automated PHP checks, but live MySQL 8 integration/race tests, authentic browser testing, SMTP/ClamAV integration and an external security review must pass before handling customer data.

## Getting started

1. Install PHP 8.4 with `pdo_mysql`, `sodium`, `fileinfo`, `gd`, `mbstring`, `opcache`; MySQL 8; Nginx; Redis optional; ClamAV for public uploads.
2. Point the web root at **`public/` only**, keep `.env` and `storage/` private, and configure HTTPS end-to-end.
3. Copy `.env.example` to `.env`, run `php bin/console setup:key`, and configure strong DB credentials, host allowlist, TLS, SMTP, security settings and tenant mode.
4. Run `php bin/build-assets`, `php bin/console module:validate`, `php bin/console compat:check`, `php bin/console migrate` on a staging environment.
5. Execute `php tests/run.php && php tests/security.php && php tests/production.php` plus the MySQL/browser/concurrency tests in the CI pipeline.
6. Run `php bin/console production:check --json`. **Do not deploy unless `ready: true` and the staging integration gates pass.**
7. Install a supervised queue worker and an external cron schedule; use the deployment/backup and rollback runbook.

**Production configuration** is intentionally strict: `APP_ENV=production`, valid generated APP_KEY, `APP_DEBUG=false`, `APP_URL=https://...`, trusted hostnames, `SESSION_SECURE=true`, `SESSION_REGISTRY_ENABLED=true`, `MEDIA_SCAN_REQUIRED=true`, `MEDIA_SCAN_DRIVER=clamav`, and all maintenance flags disabled. Supply a trusted tenant control host when using multi-tenancy. Mail may be disabled, or set to verified SMTP.

## Documents

- [Production deployment](docs/PRODUCTION_DEPLOYMENT.md)
- [Security policy and reporting](SECURITY.md)
- [Production release checklist](docs/PRODUCTION_CHECKLIST.md)
- [Architecture and extension contract](docs/EXTENSION_API_1.0.md)
- [Module Builder and relations](docs/DEVELOPER_STUDIO_1.6.md)
- [Upgrade from v1.6.0](docs/UPGRADE_1.6.1.md)
- [Contributor guidance](CONTRIBUTING.md)


## Editing and publishing on GitHub

**Source hashes are not pinned.** `RELEASE.json` contains informational release metadata only, and the publication ZIP does not include `SHA256SUMS.txt`. You may edit files and commit changes normally; Git records revisions itself. You do not need to edit a hash manifest after every change.

Run `php bin/build-assets` after changing CSS or JavaScript sources; it regenerates `public/assets/manifest.json` and content-fingerprinted assets. **Do not manually rename or delete those build files without rebuilding.** Signed `.bfp` module packages are different: editing one invalidates its signature, and it must be built and signed again by a trusted publisher.

See [Editing and publishing guide](docs/GITHUB_EDITING.md).

## Source availability and licensing

This repository is publicly readable. **No open-source license has yet been selected**; public visibility alone does not grant redistribution or modification rights. Select and add a license before encouraging third-party distribution.

## Release history

# Brightery Framework v1.6.0

**PHP 8.4+ modular MVC**, optional single-/multi-tenancy, hybrid PHP/JavaScript UI, upgrade-safe application modules, and a developer studio. Public Module API remains `0.2.0`.

> **Development release, not certified for production use.** PHP regression and security smoke tests can run locally; live MySQL 8, browser interaction, worker concurrency, and independent security evaluation remain required staging gates.

## New in v1.6.0

- **Many-to-many relationships for new generated modules** (`type: many_to_many`, `references: TargetModule`, `required: false`). The builder generates a bounded join table with foreign keys and target module dependencies. It does not add a fake column on the primary table.
- **Tenant-scoped atomic relationship CRUD.** Up to 50 distinct selected target IDs; active same-scope records only; transactional create/sync; source-row locking on update; target FK cleanup on permanent deletion; related records cannot be read outside the authorized tenant. Optional soft-deleted targets cannot be newly linked.
- **Multi-select AJAX relation controls** with searchable results, removable chips, and DOM-based rendering without inserting remote HTML.
- **Advanced data-grid actions:** permission-protected CSV export of the first 100 filtered, active rows with formula-execution mitigation, plus an atomic 50-row bulk restore for soft-deletable modules.
- **Additive relationship schema evolution:** existing generated modules may append optional `many_to_many` fields using reviewed join-table migrations. Old application-owned PHP remains untouched; source and target schemas must already exist and share scope.
- **Transaction savepoint support** for nested framework transactions, without committing the surrounding caller transaction.

**Limits:** New controls/routes are generated for newly created modules. Existing application-owned module controllers and routes are not auto-overwritten. Many-to-many fields are intentionally unavailable as direct SQL sorting/filtering columns. CSV excludes virtual relationship fields and is capped at 100 records. There is no automatic database downgrade for join-table migrations, and a signing key does not sandbox module code. A source/target SQL record can still be accessed outside the framework through an independently privileged database connection; database-level tenant permissions are not provided.

## Quick start

```bash
cp .env.example .env
php bin/console setup:key
# Configure MySQL 8, host allowlist and security settings.
php bin/build-assets
php bin/console compat:check
php bin/console module:validate
php bin/console migrate
php tests/run.php
php tests/security.php
```

For existing v1.5.0 installations, see [Upgrade v1.6.0](docs/UPGRADE_1.6.0.md), [Developer Studio v1.6](docs/DEVELOPER_STUDIO_1.6.md), and [Release Verification](docs/RELEASE_1.6.0.md).

---

## Earlier release notes


# Brightery Framework v1.3.0

**Modular PHP 8.4+ MVC with both single-tenant and multi-tenant deployments, server-rendered/hybrid JavaScript UI, and upgrade-safe modules.** Public Module API remains `0.2.0`.

> **Staging milestone, not production certification.** Local regression/security/lint and generated-PHP checks pass. Disposable MySQL CRUD/tenant-isolation integration tests have been added to CI but cannot run here without `pdo_mysql`. Independent penetration, performance and real browser checks remain required.

## New in 1.3.0

- Declarative same-scope relationships between generated CRUD modules with tenant-aware target validation and a bounded relation picker (up to 100 recent records).
- Generated CRUD search, exact filters and bounded pagination in the admin/tenant web view and REST API.
- `evolve:module` append-only schema evolution and `module:migrate-disabled` to migrate offline before reactivation; review digest and maintenance safeguards.
- New regression and CI-disposable-MySQL checks for relations, scoped query results, and preservation of old records after migration.

**Limitations:** No DB-enforced foreign keys yet; relation dropdowns are limited to 100 options (plus the selected item). Live MySQL/Chromium tests are not marked as passed unless run in your environment. MySQL DDL rollback is manual.

[Module Builder 1.3](docs/MODULE_BUILDER_1.3.md) · [Upgrade 1.3.0](docs/UPGRADE_1.3.0.md) · [Release verification](docs/RELEASE_1.3.0.md)

## Previously delivered in 1.2.0

- **Module Builder** (`/admin/developer`): visually define module name, central/tenant scope and validated typed database fields; inspect generated file list and SQL, then confirm creation. No arbitrary PHP/SQL execution and no implicit migrations. Production web writes require `MODULE_BUILDER_MAINTENANCE=true`.
- **CLI scaffolding** (`php bin/console make:module path/to/blueprint.json [--apply]`): dry-run by default. Production apply requires `MAINTENANCE_MODE=true`.
- **Generated MVC/CRUD modules**: each app-owned module contains a model, controller, routes, manifest, schema and MySQL migration; central RBAC permissions or tenant-scoped data with existing role guards; generated REST APIs require scoped bearer tokens.
- **API token scopes**: `modules:read`, `modules:write`, validated alongside existing scopes. Central permissions are checked in addition to token scope. Normal users receive no implicit grants.
- **Regression and disposable-MySQL tests**: generated source syntax, no-overwrite, schema tampering rejection, route registration, input validation, tenant-isolation integration checks.
- **No framework-owned schema migration**; generated modules carry their own explicit migrations. Form Studio and reviewed module switching remain available.

[Developer Module Builder Guide](docs/MODULE_BUILDER_1.2.md) · [Upgrade 1.2.0](docs/UPGRADE_1.2.0.md)

## Installation and verification

```bash
cp .env.example .env
php bin/console setup:key
# Configure database, allowed hostnames, mail, TLS and permissions before installing.
php bin/build-assets
php bin/console compat:check
php bin/console module:validate
php bin/console migrate
php tests/run.php
php tests/security.php
```

For an existing site, **use the v1.1.0 → v1.2.0 upgrade ZIP** and back up first. Run the CLI with the same site-level user used by PHP-FPM where possible. Point Nginx only to `public/`.

---

## Previous 1.1.0 release notes

# Brightery Framework v1.1.0

**Modern modular PHP 8.4+ MVC**, optional single-/multi-tenancy, hybrid progressive UI and upgrade-safe extension APIs. This release adds reviewed module activation, a private form schema designer and stronger testing workflows. Public Module API remains `0.2.0`.

> **Development milestone, not production certified.** Local PHP tests, module compatibility, PHP/JS lint and security smoke tests passed. MySQL 8, GD, Chromium E2E and full Nginx/PHP-FPM benchmarks were not executed in this workspace; CI/staging must verify them before live use.

## New in 1.1.0

- **Reviewed module activation**: dependency/version-aware previews, explicit confirmation and atomic stale-plan rejection (`module:plan NAME enable|disable` / Admin → Modules). No unreviewed browser one-click switching. See [Module Approval](docs/MODULE_APPROVAL_1.1.md).
- **Form Studio**: admin-only visual field editor with text, email, number, date, textarea, select, checkbox, file and AJAX autocomplete, JSON import/export and private upgrade-safe schema storage. Server validation and safe HTML rendering; **does not auto-create public submission endpoints**. See [Form Studio](docs/FORM_STUDIO_1.1.md).
- **Browser UI test** plus improved CI with a separate Chromium job; security tests now cover the protected form editor and module review route.
- **Observed HTTP profiling script** with bounded local requests; formal production benchmarking still requires representative infrastructure.
- **Admin version labels** now follow Kernel::VERSION rather than stale historic text. No new SQL migration.

## Install / upgrade

Use the full installation ZIP for new systems; for existing 1.0.0 installs apply the smaller upgrade pack and follow [Upgrade 1.1.0](docs/UPGRADE_1.1.0.md). Keep `.env`, `app/`, private storage and application-owned modules. Build assets then run:

```bash
php bin/build-assets
php bin/console compat:check
php bin/console module:validate
php tests/run.php
php tests/security.php
php bin/console deploy:preflight --json
```

[Testing matrix](docs/TESTING_1.1.md) · [Module review](docs/MODULE_APPROVAL_1.1.md) · [Form Studio](docs/FORM_STUDIO_1.1.md)

---

## Previous 1.0.0 notes

# Brightery Framework v1.0.0

**Modular PHP 8.4+ MVC framework** with upgrade-safe modules, optional single- or multi-tenant applications, hybrid server-rendered PHP/JavaScript controls, MySQL 8, private media and queue processing, signed modules, and light/dark administration.

> **1.0.0 is an API-and-tooling milestone, not a production certification.** PHP smoke tests and local HTTP tests passed, but live MySQL 8 integration/race, real media processing, SMTP/ClamAV, full-scale load and independent penetration tests have not been completed in this workspace. Deploy only after staging verification.

## What's new in 1.0.0

- **Stable extension method contract** checked by `php bin/console compat:check`. Public Module API remains `0.2.0`; System module remains `0.3.4`. Core internals are not all frozen; see [Extension API](docs/EXTENSION_API_1.0.md).
- **Structured, privacy-aware request logs** with server-issued `X-Request-ID`, duration, status, and sanitized paths; protection for reset/invite tokens. Read-only `ops:requests --limit=2000` and protected Operations dashboard display p50/p95/5xx; [Observability](docs/OBSERVABILITY_1.0.md).
- **Upgrade-safe logger binding** via `LoggerInterface` and `app/overrides.php`.
- **Reproducible CI configuration** for PHP 8.4, disposable MySQL 8 schemas, PHP/JS lint, database integration, concurrency quota tests and localhost HTTP smoke. [Testing matrix](docs/TESTING_1.0.md).
- **Security smoke + bounded test harness** to distinguish validated safeguards from untested staging/production behaviors.
- **No new DB migration** from 0.9.0; additive code changes only. Follow [v1.0.0 upgrade guide](docs/UPGRADE_1.0.0.md).

## Quick start

```bash
unzip brightery-framework-v1.0.0.zip
cd brightery-framework-v1.0.0
cp .env.example .env
php bin/console setup:key
# Set DB host/schema/credentials, APP_URL, TRUSTED_HOSTS, TLS and SMTP.
php bin/build-assets
php bin/console module:validate
php bin/console compat:check
php bin/console migrate
php bin/console user:create admin@example.com "Platform Admin"
php bin/console doctor
php tests/run.php
php tests/security.php
```

Point your web server **only** at `public/`. Keep `.env`, `storage/` and signing keys private. Use Supervisor for CLI queues and an external cron for the UTC scheduler. Production requires PHP with `pdo_mysql`, `sodium`, `fileinfo`, and MySQL 8; GD is needed for image variants. Apache/Nginx compression, cache policy and PHP OPcache must be configured/verified at the server tier.

## Developer and operator entry points

```bash
php bin/console compat:check
php bin/console ops:requests --limit=2000
php bin/console ops:status --json
php bin/benchmark --iterations=10000
php tests/run.php
php tests/security.php
# MySQL CI: configure TWO empty databases matching brightery_test_* names
bash scripts/ci-verify.sh
```

The local benchmark is deliberately an in-process **microbenchmark** and must not be advertised as real HTTP requests per second. The repository's CI workflow must actually be run in your own environment before database or cross-process tests can be considered passed.

---

## Prior releases (historical notes)

# Brightery Framework v0.9.0

Upgrade-safe **PHP 8.4+ modular MVC** platform supporting both single-tenant websites and multi-tenant applications, MySQL 8, secure account/session services, hybrid PHP/JS controls, protected media, Arabic/English content and signed modular add-ons.

> **Development release; not production certified.** Local smoke and syntax checks do not replace MySQL integration, concurrency/load, file/image pipeline testing, real email, antivirus, or external security review.

## New in v0.9.0 — reliability and operations

- **Queue worker heartbeat registry** using migration `008_operations_reliability.sql`; central `ops:status --json` reports overdue queued jobs, worker recency and failed-job totals, and an expanded protected Operations dashboard.
- **Deployment preflight and release finalization:** `deploy:preflight` validates configuration, modules, environment, runtime directories and migration status; `deploy:finalize` previews first. `--apply` uses an exclusive local lock, existing migration lock, file-cache flush and a private version marker. It does not deploy files, stop traffic or take backups.
- **Media-storage reconciliation:** `media:reconcile --tenant=N` or `--central` previews quota drift, missing/corrupt files and invalid keys. Explicit maintenance-gated `--apply --confirm=SCOPE` adjusts **only** DB accounting inside a quota row lock. No automatic removal of private files.
- **Extension continuity:** `Kernel::VERSION=0.9.0` while public Module API stays `0.2.0` and System module remains `0.3.4`. No breaking theme or module changes.

Important references: [Operations](docs/OPERATIONS_0.9.0.md), [Upgrade from 0.8](docs/UPGRADE_0.9.0.md), [Security](docs/SECURITY_0.9.0.md).

## Quick start

```bash
unzip brightery-framework-v0.9.0.zip
cd brightery-framework-v0.9.0
cp .env.example .env
php bin/console setup:key
# Configure the MySQL schema and user, APP_URL, TRUSTED_HOSTS, production TLS and SMTP.
php bin/build-assets
php bin/console module:validate
php bin/console migrate
php bin/console user:create admin@example.com 'Platform Admin'
php bin/console doctor
php tests/run.php
```

For an existing installation, use the v0.9.0 upgrade pack **without replacing application-owned files or runtime storage**. Follow [UPGRADE_0.9.0.md](docs/UPGRADE_0.9.0.md) and take a tested backup before migration.

---

## Previous release history

### v0.8.0 signed modules

Upgrade-safe **PHP 8.4+ modular MVC** foundation with optional single-/multi-tenancy, MySQL 8, admin/frontend separation, hybrid PHP + JS form controls, light/dark admin UI, secure sessions, MFA, queues, APIs, caching and private media.

> **Development release, not production certified.** Unit/smoke, PHP syntax, module checks and package integrity tests were run. Live MySQL, GD, real antivirus scans, SMTP, load/concurrency and penetration testing are required in staging before production use.

## In v0.8

- **Signed `.bfp` module distribution:** JSON-base64 bounded payloads with detached Ed25519 signatures and locally pinned public keys, requiring `ext-sodium` but not PHP ZIP.
- **CLI-managed module trust:** explicit public-key trust/revocation, local signing-key generation, package creation and verification; private keys must stay out of the web root.
- **Safe installation defaults:** manifest/dependency validation, version checks, symlink/path traversal rejection, nonmutating dry runs, and opt-in `--apply`.
- **Upgrade backups and file rollback:** on-module-update private snapshots, listable backup identifiers, dry-run rollback and explicit restore with version verification. Database migrations are *not* rolled back.
- **Production maintenance requirement:** applying signed module updates in production requires `MODULE_MAINTENANCE=true` in addition to separate web-tier maintenance and stopped workers.
- **No new database migrations;** public Module API remains `0.2.0` and System module `0.3.4`.

Full developer and operational guidance: [Signed Modules](docs/SIGNED_MODULES.md), [Upgrade v0.8](docs/UPGRADE_0.8.0.md), and [Security v0.8](docs/SECURITY_0.8.0.md).

## Previous v0.7 foundation

- **Tenant media quotas:** central/tenant usage tracked with atomic InnoDB row locks. Both originals and generated image variants count toward the byte cap; originals count toward the file cap. Default is **512 MiB / 2,000 original files** per tenant and central scope, configured with env or per-tenant overrides in Admin → Tenants. Additive migration `007_media_quotas.sql` backfills existing media sizes.
- **Asynchronous private thumbnails:** successful image uploads atomically enqueue `system.media.thumbnail`, which builds a 320×320 JPEG variant using the existing private GD image pipeline. The handler is registered in `modules/System/boot.php`; jobs preserve tenant scope, retry on failure and deduplicate generated variants. Requires ext-gd + queue worker.
- **Antivirus adapter contract:** `ScannerInterface` with `DisabledScanner` and `ClamAvScanner` using a local UNIX socket (clamd INSTREAM); malware and scanner errors reject the upload when enabled. The default `disabled` driver does **not** scan. Set `MEDIA_SCAN_REQUIRED=true` to forbid disabled operation.
- **Localized emails:** English and Arabic text templates for password reset, account email verification and tenant invitations, loaded from fixed `resources/mail/*.json` files via override-friendly `TemplateCatalog`. The existing encrypted outbox and TLS SMTP transport remain in place. `MAIL_LOCALE=ar` selects Arabic globally (per-user preferences are not implemented).
- **More tenant isolation:** upload service checks company membership; protected media routes retain permission checks; upload rate limiting added. Added disposable-MySQL tests for quotas, cross-company reads and deletions, as well as a six-process race test for concurrent uploads.
- **Upgrade compatibility:** `Kernel::VERSION=0.7.0`, unchanged public module API `0.2.0`, System module `0.3.4` (same compatible 0.3.x line). Existing `app/overrides.php`, custom modules/themes, `.env`, and storage remain application-owned.

## Requirements

- **PHP 8.4+** with `pdo_mysql`, `fileinfo`, `sodium`, `json`. **GD** for image transforms and asynchronous thumbnails. OPcache, mbstring recommended.
- **MySQL 8+**, TLS, MySQL UTC timezone, PHP-FPM + Nginx, restricted `storage/` outside the public document root, private `.env` with `APP_KEY`.
- A supervised CLI queue worker and cron scheduler; real SMTP for invitation/recovery delivery. ClamAV daemon with UNIX socket, or a custom scanner, if upload malware scanning is mandatory.
- Point Nginx document root to `<project>/public`, never to project root. Do not cache private media/account/API responses.

## Fresh install

```bash
unzip brightery-framework-v0.8.0.zip
cd brightery-framework-v0.8.0
cp .env.example .env
php bin/console setup:key
# Set DB_DATABASE, DB_USERNAME, DB_PASSWORD, APP_URL, TRUSTED_HOSTS,
# SESSION_SECURE=true, TENANCY_MODE, SMTP and media settings.
php bin/build-assets
php bin/console module:validate
php bin/console migrate
php bin/console user:create admin@example.com 'Platform Admin'
php bin/console doctor
php tests/run.php
```

**Upgrades:** See [docs/UPGRADE_0.8.0.md](docs/UPGRADE_0.8.0.md), and back up MySQL + private storage together before changing files. New migration counts previously uploaded original and derived media. Stop uploads/workers during the migration.

## Queue / scheduler

```bash
php bin/console schedule:run
php bin/console queue:work --max=100
php bin/console queue:stats
```

The System module registers `system.media.thumbnail` for background jobs. The upload creates its job in the same database transaction as the media row and quota reservation. The queue worker must be supervised and should run with resource limits. Missing GD means thumbnail jobs will fail until GD is installed; set `MEDIA_AUTO_THUMBNAILS=false` meanwhile.

## Configuration example

```dotenv
MEDIA_QUOTA_BYTES=536870912
MEDIA_QUOTA_FILES=2000
MEDIA_AUTO_THUMBNAILS=true
MEDIA_SCAN_DRIVER=clamav
MEDIA_SCAN_REQUIRED=true
CLAMAV_SOCKET=/run/clamav/clamd.ctl
MAIL_LOCALE=ar
```

If clamd is not installed, use `MEDIA_SCAN_DRIVER=disabled` and `MEDIA_SCAN_REQUIRED=false` **only** in a deployment where accepting unscanned files is explicitly permitted. The default is unscanned and should not be mistaken for antivirus protection.

## Customization without modifying framework files

`app/overrides.php` registers app-owned services through DI. Replace the scanner with another `ScannerInterface` implementation, or bind an application subclass of `TemplateCatalog` to provide different content. Add modules under `modules/` with module manifests, dependency constraints and route/boot hooks; avoid editing framework core. The API remains semver-versioned separately from the framework release.

## Tests

```bash
php tests/run.php
php bin/console module:validate
find core modules -name '*.php' -print0 | xargs -0 -n1 php -l
node --check public/assets/js/app.js
# Against two separate EMPTY disposable MySQL schemas:
TEST_DB_DATABASE=brightery_test_integration php tests/integration_mysql.php
TEST_DB_DATABASE=brightery_test_race php tests/concurrency_mysql.php
```

The MySQL test scripts **refuse non-test schema names** and won't run against a non-empty schema. They are included but could not be executed in the build environment because it does not include `pdo_mysql`. GD and live ClamAV were unavailable as well.

## Known limitations

- No WebAuthn/passkeys, public signed URLs, subject-aware smart crop, rich email HTML, accessible drag-drop browser E2E verification, per-user email locales, true file streaming for very large media, or fully managed malware daemon.
- The primary legacy `/admin/media` route (public-asset media prototype) is not subject to private-media quotas. Use `/admin/media/private` or `/workspace/media` for quota-protected tenant media.
- Storage counters require all media mutations to use framework APIs; manual SQL/file changes cause drift. Reconciliation/orphan sweeper and operational dashboards need a later milestone.
- Additional tenant scopes in third-party modules must be audited independently. Security certification is not claimed.

See [docs/SECURITY_0.8.0.md](docs/SECURITY_0.8.0.md), [Upgrade Guide](docs/UPGRADE_0.7.0.md) and [docs/ROADMAP.md](docs/ROADMAP.md).
