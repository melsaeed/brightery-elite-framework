# Brightery Framework v1.6.0 — Verification and remaining risks

Local verification covers PHP linting, JavaScript syntax checks, previous-release regression tests, new blueprint/pivot/ACL edge cases, protected routes and upgrade preservation. Accurate execution counters and per-file hashes are recorded in `RELEASE.json` and `SHA256SUMS.txt`.

**Not locally verified:** actual MySQL DDL, SQL foreign keys/savepoints in a live DB, concurrent AJAX writes, runtime image processing, Chromium UI flows, Nginx/PHP-FPM production load, and independent security testing. The environment does not provide `pdo_mysql` or a running MySQL server. Added disposable-MySQL integration cases are **test code**, not successful test runs. Run the included CI pipeline and inspect its reports before enabling this release for real tenant data.

Review the use of FK cascades against retention policies. Disabling or deleting source/target records can affect their relationships; soft-deleting targets merely hides choices and prevents new selection, rather than deleting link rows. CSV has a 100-row cap and intentionally excludes virtual relation fields. No DB-level row security or automatic rollback is promised.
