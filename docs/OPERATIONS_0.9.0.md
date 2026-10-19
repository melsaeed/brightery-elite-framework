# Brightery Framework 0.9.0 — production operations guide

The framework retains the v0.2 module contract and the System 0.3.x module line. The v0.9 services are additive. **These tools are not a managed deployment service, a distributed tracing system, or a high-availability guarantee.** The deployment runner does not transfer application code, restart processes, provision TLS, or automatically back up/roll back a database.

## Runtime requirements

- PHP CLI 8.4+ with `pdo_mysql`, `sodium`, `fileinfo`; MySQL 8; PHP-FPM and Nginx for HTTP traffic.
- Site document root must be `public/`, never the repository root. Keep `storage/private`, `.env`, and module signing keys unreachable from HTTP.
- Run `php bin/console migrate` **before** `queue:work` for this release; new worker heartbeat records require migration `008_operations_reliability.sql`.
- Multi-tenant operations are **central-only** and should be restricted to administrators. Tenant media reconciliation always needs an explicitly selected scope.

## Deployment preflight and finalization

After performing an application/database/files backup and restoring it in staging:

```bash
php bin/console module:validate
php tests/run.php
php bin/console deploy:preflight --json
php bin/console deploy:finalize      # DRY RUN, no migrations/cache modification
```

`deploy:preflight` returns a nonzero status on failed controls, and reports warnings separately. It tests installed PHP/runtime extensions, key format, production session/debug configuration, host allowlist, runtime storage, module dependencies, and migration status. It never outputs `APP_KEY` contents. The DB status check may bootstrap the `schema_migrations` tracking table. **In production, apply only after externally draining requests and workers**:

```bash
# maintenance flag only for this controlled deployment window
# set DEPLOY_MAINTENANCE=true via .env or process environment
php bin/console deploy:finalize --apply
```

`deploy:finalize --apply` takes a nonblocking local `flock`, reruns preflight, applies pending migrations using the existing database migration lock, checks migration status again, clears local file cache, and atomically writes `storage/private/release-state.json` with version and migration names. **It does not deploy/swap code, take backups, stop traffic, restart PHP-FPM, or roll back DDL.** Use your existing deployment manager for those tasks. Because MySQL DDL isn't transaction-safe, take a tested backup before finalization. The `DEPLOY_MAINTENANCE` variable must be reset to `false` immediately afterward.

## Queue health and worker registry

```bash
php bin/console queue:work --max=100
php bin/console ops:status --json
php bin/console ops:prune --days=14
```

Each CLI `queue:work` invocation registers an unpredictable worker ID and updates its heartbeat while starting and completing jobs; the worker stops on normal exit. `ops:status` groups queue counts, overdue pending jobs, and recent/stale worker records. The central Admin > Operations page shows the same overview. Heartbeat records are not secrets or personal identifiers. `ops:prune` deletes old worker records only.

**Important concurrency limitation:** Heartbeats are not a substitute for renewing **job leases**. The queue defaults to a 120-second reservation. Work that exceeds the lease must use `Queue::heartbeat($job, $leaseSeconds)` (or be broken into short, idempotent jobs). If a lease expires, other workers may retry. Jobs must be idempotent, and external side effects must be protected by their own deduplication keys.

The registry measures invocations of the bundled `queue:work` CLI, not arbitrary custom worker loops. Normal shutdown records `stopped`; process kill/power loss results in a stale record until pruned. No process-termination button is provided in the web UI.

## Tenant media reconciliation

```bash
php bin/console media:reconcile --central
php bin/console media:reconcile --tenant=42
```

For each scope, the preview reports the stored quota counters, independently aggregated rows (originals and variants), numeric drift and a capped check of actual private files (missing, incorrect size, unsafe storage key). `files.truncated=true` means the disk scan covered only a sample, though DB aggregates cover all rows. There is **no** automatic file deletion, orphan cleanup, or storage migration.

To fix **DB counters only** following investigation, drain uploads/media workers externally, back up the DB and private media together, set `MEDIA_RECONCILE_MAINTENANCE=true`, and pass explicit flags:

```bash
php bin/console media:reconcile --tenant=42 --apply --confirm=42
php bin/console media:reconcile --central --apply --confirm=central
```

Repairs lock the selected quota row `FOR UPDATE` and recompute aggregate counts inside that transaction, using the same row serialization as normal media reservations. They do not restore missing media files or remove orphans. Check for missing/mismatched files before deciding to repair. Reset the maintenance flag when finished.

## Integration and acceptance gates

Run on an existing **empty, disposable** database named `brightery_test_<suffix>` with `pdo_mysql` installed:

```bash
TEST_DB_DATABASE=brightery_test_v09 php tests/integration_mysql.php
# Run concurrency separately on another clean disposable DB.
TEST_DB_DATABASE=brightery_test_race php tests/concurrency_mysql.php
```

The integration suite exercises worker state, failure detection, media counters/repair and tenant scope, as well as earlier auth/media/queue behavior. Both suites refuse a non-test database. MySQL staging, worker/lease concurrency, real GD, external SMTP/ClamAV, load testing, and security review are not verified by local smoke tests.
