# v1.5.0 Verification and Known Risks

The release package must pass PHP lint, `tests/run.php`, `tests/security.php`, JavaScript syntax, `compat:check`, `module:validate`, ZIP integrity, and upgrade preservation simulation. Exact passing counts and CLI captures are in `RELEASE.json`.

**MySQL 8, GD and browser:** not available in the local build environment. Real CRUD transactions, soft-delete tenant isolation, concurrency and Chromium tests need CI/staging execution. `tests/integration_mysql.php` includes newly generated soft-delete and cross-tenant restore, relations and rollback cases, but inclusion is not execution.

**Security limitations:** no external penetration test; no automatic foreign keys/cascades; module code is trusted (signed packages are not sandboxed); no permanent-delete endpoint; restored rows keep existing references; constraints remain subject to MySQL DDL and permission behavior. No promised benchmark is derived from the local PHP server.
