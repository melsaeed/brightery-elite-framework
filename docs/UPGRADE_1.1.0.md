# Brightery Framework 1.1.0 — upgrade from 1.0.0

This release is **additive** and retains public Module API `0.2.0`. `System` advances from 0.3.4 to 0.3.5 within its existing compatible version line. **There are no new SQL migrations.**

1. Make tested backups of the MySQL database, `.env`, `storage/private`, themes and application modules. Use staging first; stop workers for file replacement and ensure maintenance at the web tier.
2. Unzip the changed-files upgrade package over your v1.0.0 project root, not into `public/`. It never includes `.env`, `storage/`, `app/`, or non-System application modules.
3. Run:

```bash
php bin/build-assets
php bin/console compat:check
php bin/console module:validate
php bin/console migrate:status
php tests/run.php
php tests/security.php
php bin/console deploy:preflight --json
```

4. Clear configured caches and reload FPM/workers in your deployment process. Run browser tests in CI or a desktop/staging environment; these require `python -m pip install playwright==1.57.0` and `python -m playwright install chromium`.
5. In Admin → Modules, test a non-core module's preview and confirmation workflow. The approval token is tied to current module states and manifest versions and is checked again under the state-file lock.
6. In Admin → Form Studio, create and edit a sample form. Definitions persist under `storage/private/forms`, outside framework-owned files; preserve this path across upgrades and back it up.

## Compatibility and limitations

- Existing CLI `module:enable` and `module:disable` remain explicit administrative actions without the browser's confirmation step; operators should use `module:plan` first.
- The module preview does **not** install code, reverse migrations, perform DB schema changes, or restart long-running processes. Stop workers during code upgrades and run any new module migrations separately.
- The Form Studio creates **schema definitions and previews only**. No public write endpoint is implicitly generated. Each application must authorize, validate, and process submissions, and must use private-media controls for uploads.
- The previous MySQL 8 integration/race suites are unchanged and remain mandatory before production deployment. This workstation lacks `pdo_mysql` and GD, so they have not been run in this release environment.
- Browser test code is provided in `tests/browser_ui.py` and CI, but browser navigation is blocked in the local execution environment. A CI run is needed before treating E2E as passed.
- Signed modules, central/tenant authorizations, sessions and token handling must still undergo a dedicated penetration test before production security claims.
