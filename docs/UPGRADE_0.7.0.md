# Upgrade Brightery Framework v0.6.0 → v0.7.0

**Staging deployment only until MySQL, GD, clamd, queue and SMTP are verified.** This release changes how media is stored and deleted. Take restorable backups of the database **and** `storage/private/media/` together.

## Procedure

1. Stop Supervisor queue workers and cron scheduler; enable maintenance mode. Record deployed module overrides and asset manifests. Never overwrite a customized framework-owned file without reviewing the diff.
2. Extract `brightery-framework-v0.7.0-upgrade.zip` **into your existing project root** (archive paths are relative). The pack excludes `.env`, `app/`, app themes, unmodified custom modules, and `storage/` runtime contents. Merge configuration changes manually from `.env.example`.
3. Configure `MEDIA_QUOTA_BYTES` and `MEDIA_QUOTA_FILES` according to actual existing volumes. Defaults: 512 MiB and 2,000 original files per tenant, including central scope 0. The quota counts **both** originals and generated variants toward the byte cap. A tenant already above a new cap can read/delete but cannot upload or generate more.
4. Decide antivirus policy: set `MEDIA_SCAN_DRIVER=clamav`, `MEDIA_SCAN_REQUIRED=true`, and `CLAMAV_SOCKET=/run/clamav/clamd.ctl` for fail-closed scanning. Verify the PHP-FPM account can access the clamd socket. The default `disabled` driver is not antivirus protection. Fail-closed means bad/missing scanner configuration rejects uploads rather than silently storing unscanned bytes.
5. `MEDIA_AUTO_THUMBNAILS=true` queues private 320×320 center-contained JPEGs. **GD and an actively running queue worker are required**. If either is missing, set false until available. Existing images are not automatically backfilled.
6. Configure `MAIL_LOCALE=en` or `ar` (templates live in `resources/mail/`). Email templates are plaintext and may be overridden by binding `TemplateCatalog` to an application subclass/implementation. This does not add HTML email.
7. Run from the project root on staging:

```bash
php bin/build-assets
php bin/console module:validate
php bin/console migrate:status
php bin/console migrate
php bin/console doctor
php tests/run.php
php bin/console route:list
```

8. Migration `007_media_quotas.sql` creates `bf_media_usage` and `bf_media_quotas`, and **backfills existing original and variant sizes**. MySQL DDL is not transactionally rolled back. Run the migration with uploads/workers stopped, otherwise the backfill may race live uploads. If interrupted, inspect before re-running. Set per-tenant overrides under **Admin → Tenants → Set company media quota**.
9. Run DB tests against **separate, empty, disposable** MySQL schemas with names starting `brightery_test_`:

```bash
TEST_DB_DATABASE=brightery_test_integration php tests/integration_mysql.php
TEST_DB_DATABASE=brightery_test_race php tests/concurrency_mysql.php
```

10. Verify private media permissions and original/variant deletion for two real tenant-domain accounts, background thumbnail creation and job retries, clamd infection detection using the official harmless antivirus test pattern, SMTP in both locales, user login/MFA continuity and CDN no-store behavior. Restart workers, cron and PHP-FPM after verification.

## Compatibility and rollback

- Framework `0.7.0`, stable public module API `0.2.0`; System module `0.3.4` (within the `0.3.x` API line). Custom classes and themes remain outside framework-owned directories. Runtime data and `APP_KEY` are untouched.
- DB new quota counters must remain synchronized with media rows: **do not** perform direct SQL deletes or direct file deletes. Delete through `PrivateMedia` or `ImageVariants` methods. Existing untracked legacy `/admin/media` uploads are not counted.
- Avoid restoring just the old code with the new DB counters: restore a **matching** snapshot of code, DB and private media storage for rollback. Queue jobs from v0.7 may be unrecognized to older workers; pause and drain/drop only after reviewing the queue.
- The v0.7.0 code is not production security-certified. WebAuthn/passkeys, full accessibility checks, real clamd integration, multi-worker performance testing, and third-party security review remain separate work.
