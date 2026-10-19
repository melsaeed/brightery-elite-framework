# Verification matrix 1.1.0

Run `php tests/run.php`, `php tests/security.php`, `php bin/console compat:check`, PHP lint, and `node --check public/assets/js/form-studio.js` locally. Module-plan and declarative-form tests use isolated temporary directories; they do not need a DB.

**Browser test:** `python tests/browser_ui.py` runs Chromium against a disposable localhost fixture, testing add/edit/save serialization, JSON import and DOM safety. Requires Playwright + Chromium. It does not test authenticated database workflows; run those in a staging application.

**Disposable MySQL test:** `.github/workflows/integration.yml` has a PHP 8.4 + MySQL 8 job invoking `scripts/ci-verify.sh` against distinct empty `brightery_test_*` schemas. The tests must not point to live data. A separate browser job runs Playwright. CI is provided, but must run in your repository before considering these integrations verified.

**HTTP sampler:** after configuring a staging PHP-FPM/Nginx stack, run `python scripts/profile-http.py --url=http://127.0.0.1:8787/health --requests=500 --workers=16` against localhost only. It reports observed throughput and latency, not a universal benchmark. Compare production-like Nginx/OPcache/compression, real DB traffic and baseline profiler data before setting an SLA.

Out of scope: a full Nginx/PHP-FPM profile on this workstation, independent security testing, and production acceptance. Browser launching/network navigation restrictions prevent a local browser pass here; do not confuse JavaScript syntax checks with E2E tests.
