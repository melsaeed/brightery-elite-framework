# Upgrade: Brightery Framework v0.3.0 → v0.4.0

Release v0.4 is an **additive schema + core** upgrade. Preserve `.env`, `app/overrides.php`, `app/themes/**`, `storage/**`, and any user-owned `modules/**`. The provided upgrade ZIP has changed/new framework-owned files only, with root-relative paths.

1. Back up application code and MySQL, verify a restorable backup, and clone onto a staging hostname using the same PHP-FPM version and MySQL 8 features. Stop background workers (if any) and put writes into maintenance mode for the migration.
2. Unzip `brightery-framework-v0.4.0-upgrade.zip` into the **v0.3.0 project root**. Review any conflicts if a framework-owned file was edited manually in your installation.
3. Check PHP 8.4+, `pdo_mysql`, `fileinfo`, `sodium`, and Nginx Authorization forwarding. Ensure production HTTPS/SESSION_SECURE, TLS for all tenant domains, and restrictive file ownership.
4. Run commands from the project root:

```bash
php bin/build-assets
php bin/console module:validate
php bin/console migrate:status
php bin/console migrate
php bin/console doctor
php tests/run.php
php bin/console route:list
php bin/console schedule:list
```

5. On a **dedicated existing empty** `brightery_test_*` schema, execute `TEST_DB_DATABASE=brightery_test_ci php tests/integration_mysql.php`. Never point this at production. This test has intentionally not been executed in the provided PHP environment because `pdo_mysql` is missing.
6. Confirm `/admin/operations`, `/account/mfa`, `/api/v1/me` (401 without bearer), and tenant `/workspace/audit` (permission gated). Ensure account MFA enrollment and login challenge are validated with a real authenticator, and run an invitation acceptance test for an **existing MFA-enabled user**.
7. Configure the scheduler/worker processes. Deploy all changed files, enable `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`, reload PHP-FPM, and smoke test HTTPS on control + tenant domains.

## Compatibility notes

- Public framework `Kernel::VERSION` is **0.4.0**. Module programming API remains **0.2.x** to avoid unnecessary module breakage. Required System module semver stays **0.3.1** for dependent modules pinned to `^0.3.0`.
- A new required `Api` module registers routes but does **not** issue tokens automatically. New core migration `004_operations.sql` creates jobs, schedule slots, audit logs, personal tokens, and TOTP credentials.
- `Auth::attempt()` and `TenantAccess::attempt()` can now establish a **pending MFA challenge** instead of logging in immediately; any custom login controller should redirect to `/mfa` if `Security::pendingMfa()` is non-null.
- Member and invite mutations now require audit tables to exist. Run migration **before** opening public write traffic.
- Do not rotate `APP_KEY` without re-encrypting TOTP secrets/re-enrolling affected users.
- No `app/overrides.php`, user theme, runtime data or custom module is included in the upgrade package.

Rollback: stop worker/scheduler, restore the v0.3 code and a **matching pre-upgrade DB backup**. MySQL DDL is not automatically transactional and this framework does not provide a destructive automatic schema rollback. Avoid changing APP_KEY during rollback.
