# Upgrade Brightery Framework v0.9.0 to v1.0.0

This update is additive and includes **no new database migration**. Module API is still `0.2.0` and System module is still on `0.3.4`; pre-existing modules should remain activatable. This is a framework-level source update, not a PHP or database server upgrade.

1. Create and **verify** database, `.env`, media/storage and application/theme backups. Stage the release on a production-like clone. Drain PHP workers/queues externally before replacing code in production.
2. Extract `brightery-framework-v1.0.0-upgrade.zip` into the application root (the ZIP contains root-relative paths, not an extra directory). It deliberately excludes `.env`, `app/overrides.php`, custom themes/modules and mutable storage.
3. Add `LOG_LEVEL=info` to `.env` if you wish to record access telemetry. Grant the PHP-FPM user private write access to `storage/logs`; configure log rotation and access restrictions.
4. Run staging checks:

```bash
php bin/build-assets
php bin/console compat:check
php bin/console module:validate
php bin/console migrate:status
php bin/console deploy:preflight --json
php tests/run.php
php tests/security.php
php bin/console ops:requests
```

5. Run `scripts/ci-verify.sh` against **two new empty dedicated** MySQL test schemas. Do not point it at the live or staging application database. Review extension and third-party module overrides in staging.
6. Reload PHP-FPM and restart supervised workers using normal deployment orchestration. Verify the frontend, health, admin, session, queue and protected tenant APIs, then monitor `X-Request-ID`, errors and latency.

### Rolling back

Restore the previous *files* from verified v0.9.0 backups if needed. No schema changes were added by v1.0.0, but any app-owned migrations still need their own recovery plan. Restore corresponding private data and DB snapshots together when they were changed by application activity. Do not accidentally roll back tenant records separately from media files.

### Compatibility contract

See [EXTENSION_API_1.0.md](EXTENSION_API_1.0.md). `compat:check` is an additive safeguard, not a substitute for behavioral contract tests.
