# Upgrade Guide: v0.2.0 → v0.3.0

## Scope

Changes add company authentication, team-management routes, invitation issuance/acceptance, strict tenant permissions and a new SQL table; they preserve the module extension API of v0.2.0. Older modules declaring `framework: "^0.2.0"` remain compatible. `min_release` is optional to express a minimum product release.

## Preflight

1. Back up the full installation **and database**. Record application-specific service overrides and custom modules.
2. Stage against a COPY of production, without shared live tenant data; ensure `pdo_mysql`, PHP 8.4, MySQL 8 and a production-like tenant hostname configuration.
3. Check for custom changes made directly in `core/`, `themes/`, `modules/System/`, `bin/`, or `public/assets/`. Move intended custom theme changes to `app/themes/workspace`, `app/themes/admin`, etc. Application/module overrides must be reconciled explicitly.
4. For multi-tenant installations, configure valid TLS and confirm `TRUSTED_HOSTS` includes the control plane host. Keep `SESSION_SECURE=true` in production.

## Installation

Unpack the v0.3.0 upgrade archive **in the existing application root**. Its paths are root-relative, unlike the full ZIP. It does not include `.env`, `app/overrides.php`, custom `app/themes/`, runtime `storage/`, or arbitrary user-created modules.

```bash
php bin/build-assets
php bin/console module:validate
php bin/console migrate:status
php bin/console migrate
php bin/console doctor
php tests/run.php
php bin/console route:list
```

Migration `003_tenant_invitations.sql` creates only a new table and foreign keys; existing user/tenant/member data is not rewritten. MySQL DDL isn't transactionally rolled back by `Migrator`; restore from snapshots if schema migration fails.

Then reload PHP-FPM. Validate login, company invitation acceptance, role restrictions, tenant suspension, and disabled/unknown domains on staging before opening SaaS routes to users.

## Behavior changes

- `TENANCY_MODE=single` keeps existing global admin behavior; `/workspace` is inaccessible without a valid tenant context.
- In multi-mode, `/admin` remains central-only, `/workspace` is tenant-only, and `/invite/{token}` is tenant-host-only.
- The role policy is separate from `users` global user groups, and an invitation never attaches the new account to a privileged global group.
- Tenant member changes lock the tenant row before evaluating roles; the last owner cannot be removed/demoted.
- One-time invitation links expire in 48 hours and must be delivered privately. There is no built-in email dispatch yet. Prevent access logs, analytics and support tooling from ingesting raw invitation URLs.

## Verification / rollback

Use `TEST_DB_DATABASE=brightery_test_ci php tests/integration_mysql.php` on an **empty disposable test schema**. Database integration could not be run in the build workspace because MySQL PDO was missing. Keep maintenance backups available. To revert, restore the code and database snapshot from immediately before upgrade; **do not** simply remove the migration record and assume automatic rollback.
