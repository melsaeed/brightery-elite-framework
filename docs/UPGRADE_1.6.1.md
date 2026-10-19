# Upgrade v1.6.0 -> v1.6.1

Security-focused, non-schema patch. Back up database/files and test on staging; core 1.6.1 refuses production HTTP requests with 503 if critical configuration is unsafe.

1. Apply the changed files without overwriting `.env`, `storage/`, custom themes or application-owned modules.
2. Set required production keys from `.env.example`, especially secure sessions, valid HTTPS hostnames, and ClamAV scanning. The scanner must actually be running.
3. Run `php tests/run.php`, `php tests/security.php`, `php tests/production.php`, module/compat checks and CI MySQL/browser gates.
4. `php bin/console production:check --json` must report ready. If it does not, revert/repair environment settings **before** enabling customer traffic.
5. No new framework database migration. Public Module API remains 0.2.0.

No claim of production readiness until external/integration acceptance checks are complete.
