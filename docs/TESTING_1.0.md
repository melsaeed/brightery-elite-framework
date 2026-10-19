# Brightery Framework 1.0 — Test Matrix and CI

## Local no-DB checks

```bash
php tests/run.php
php tests/security.php
php bin/console compat:check
php bin/console module:validate
php bin/benchmark --iterations=10000
```

The in-process benchmark only measures routing + DI lookup; it does **not** measure request throughput, database, sessions, queues, PHP-FPM or network latency. The security smoke checks a small set of headers and unauthorized access paths; it is **not** a penetration test.

To run the localhost-only HTTP smoke test on an isolated development machine:

```bash
php -S 127.0.0.1:8787 -t public public/index.php
# in another terminal
python3 scripts/http_load_smoke.py --url http://127.0.0.1:8787/health --requests 80 --workers 8
```

This harness **refuses non-loopback destinations**, limits concurrency to 16 and caps requests at 500. Do not use PHP's development server results as production performance numbers. Avoid concurrent test load on real customer hosts.

## Disposable MySQL 8 CI gates

The repository includes `.github/workflows/integration.yml` to provision PHP 8.4 + extensions (pdo_mysql, sodium, fileinfo, GD), MySQL 8 and **two isolated empty** schemas (`brightery_test_ci_main`, `brightery_test_ci_race`). `scripts/ci-verify.sh` performs syntax lint, unit/smoke, security smoke, extension contract validation, module DAG validation, MySQL integration and the concurrent quota race test. The workflow also runs JS syntax checks and a local HTTP concurrency smoke.

The integration scripts **refuse non-`brightery_test_*` database names and refuse populated schemas**. They must never be aimed at real data. The CI database credentials are ephemeral demonstration credentials, not credentials for staging or production. CI execution results depend on your GitHub runner: a workflow definition alone does not mean the pipeline ran.

For manual MySQL testing, create two EMPTY disposable schemas and set matching variables:

```bash
export DB_HOST=127.0.0.1 DB_USERNAME=test_account DB_PASSWORD=temporary_test_password
export TEST_DB_DATABASE=brightery_test_manual_main
export TEST_RACE_DATABASE=brightery_test_manual_race
bash scripts/ci-verify.sh
```

## Required staging and release gates (still outstanding)

1. Run CI and record the successful workflow URL / build SHA.
2. Exercise production-equivalent MySQL transactions, queue leases and concurrent workers; audit SQL deadlocks/retry semantics.
3. Process and scan representative media using real GD and ClamAV, test quotas, disk failure and cleanup.
4. Verify SMTP delivery and TLS, mail retries, MFA, password reset and account/tenant invitations.
5. Load-test Nginx + PHP-FPM + MySQL with realistic sessions on a **private staging** environment (never use the localhost microbenchmark as proof of scale).
6. Carry out a threat model, ASVS-aligned independent security review, dependencies scan, penetration test and restore drill.
7. Verify deployment (including `opcache_reset`, workers/drain, migrations, rollback/backup), a multi-tenant isolation review of every installed third-party module and backup recovery.

**Passing local smoke tests does not grant production certification.**
