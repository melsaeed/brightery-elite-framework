# Brightery Framework 1.4.0 — Release verification

**Scope:** hybrid AJAX relation selectors; field sorting and page sizes; guarded, 50-record transactional bulk delete; application-owned validation policy generation; visual, additive schema evolution with stale-confirmation/maintenance protection.

**Executed in the build workspace (PHP 8.4.24):**

- 316 passing PHP unit/smoke assertions, including every prior version suite.
- 17 passing security HTTP smoke checks (not a penetration test).
- 153 PHP syntax checks, JavaScript syntax checks for both new hybrid scripts and compatibility contract validation for 23 public extension methods.
- 4 built-in module manifests/dependencies validated.
- Local localhost HTTP: frontend `/` 200, `/health` 200, protected `/admin/developer/evolve` 303, wrong-method evolution preview 405, unauthenticated `/api/v1/me` 401.
- Full and upgrade archives passed CRC integrity and SHA-256 source manifest. Simulated application of upgrade to the actual v1.3.0 archive passed all 316 assertions and 17 security checks, preserving `.env`, a custom theme, application module and private data.


**Not executed in this workspace:** MySQL 8 tenant-scoped relation queries or transactional deletes, actual migration against a database, Chromium interactions, independent penetration testing, real load/production Nginx/Cloudflare benchmarking. The PHP runtime lacks `pdo_mysql` and GD. MySQL integration assertions are bundled but must pass in disposable MySQL CI and staging before production use.

**Limitations:** This is not a production certification. Database foreign keys for generated relations are not created. Existing application-owned module PHP is not rewritten by upgrade. Bulk deletion is irreversible unless the application's own model adds a soft-delete policy. The visual module editor does not execute SQL migrations.
