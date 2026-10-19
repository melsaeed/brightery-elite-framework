# Upgrade Brightery Framework v0.4.0 → v0.5.0

This is an additive database migration plus security-sensitive application upgrade. **Do not upgrade a production host before testing on staging and taking a restorable database/files backup.**

1. Stop queue workers and schedulers, disable writes, back up the database, code and APP_KEY. Audit any local modifications to framework-owned files that the upgrade pack would overwrite. Keep `.env`, `app/overrides.php`, `app/themes/`, custom modules and `storage/` unchanged.
2. Extract `brightery-framework-v0.5.0-upgrade.zip` into your existing **v0.4.0 root**. Check the added config keys in `.env.example`; manually merge desired `.env` settings. Do **not** overwrite your actual `.env`.
3. Configure production HTTPS, `SESSION_SECURE=true`, `TRUSTED_HOSTS`, `MAIL_DRIVER=smtp`, `MAIL_FROM`, `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME` and `SMTP_PASSWORD`. Before mail is configured, leave `MAIL_DRIVER=disabled` and do not advertise email verification as delivered.
4. With `pdo_mysql` and MySQL 8 available, run:

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

5. Migration **`005_identity_delivery_media.sql`** introduces seven tables for queued mail, email verification/verified accounts, MFA recovery codes, account sessions, API rate buckets and private media. It does **not** modify the existing core user/media schemas, nor destroy existing data. Schema changes are not transactional in MySQL; if interrupted, inspect and re-run idempotent `CREATE TABLE IF NOT EXISTS` statements before resuming.
6. Test the dedicated disposable MySQL integration suite: `TEST_DB_DATABASE=brightery_test_ci php tests/integration_mysql.php` (schema must already exist and be empty). Test central and tenant login, authenticator challenge, MFA recovery, session revocation, email link confirmation, media access isolation and bearer API 429 behavior.
7. Test `php bin/console mail:test your-owned-test-address@example.com` followed by `queue:work --max=10`; ensure TLS-authenticated SMTP succeeds and SPF/DKIM/DMARC are configured in DNS. **Never** put SMTP secrets or links in job/debug logs. Strip `/verify-email/*` paths from Nginx/Cloudflare access logs.
8. Bring the site online, restart PHP-FPM and queue/Scheduler processes, review error logs and outgoing mail. Monitor database traffic from the new atomic API limiter and session registry.

## Compatibility and behavioral changes

- `Kernel::VERSION`: **0.5.0**. The public module API **remains 0.2.0**. System module **0.3.2**, preserving `^0.3.0` dependency constraints.
- **Existing signed-in sessions:** setting `SESSION_REGISTRY_ENABLED=true` invalidates sessions created before this migration; users must log in again. The new store does not auto-create privileged session records from legacy cookies. Disable temporarily only for diagnostic rollback and after evaluating risk.
- `/account/security` provides email verification and revocation of other sessions. Email verification currently records address ownership and is **not automatically enforced for every login or invitation**.
- **MFA enrollment:** new enrollment generates one-time recovery secrets. Old authenticator accounts can generate codes with a fresh TOTP at `/account/mfa`.
- Existing `/admin/media` controls remain; newer tenant-safe `/admin/media/private` and `/workspace/media` use different storage and tables. No migration of existing uploads is automatic.
- `Database::pdo()` now sets the connection timezone to **UTC** for consistent TTL and quota operations.
- Queued mail is retried but delivery can duplicate on post-SMTP crash; design business emails for idempotency.

## Rollback

Stop queue workers and schedulers, restore **matching** v0.4 code and a pre-upgrade MySQL backup, retaining APP_KEY. Restoring code without restoring database state may leave auth/session mismatches. Do not automatically drop v0.5 tables if they already contain user data. Before rollback, ensure any new media or queued emails are reconciled.
