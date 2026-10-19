# Signed Brightery Module Packages (.bfp)

Brightery Framework v0.8 introduces a **CLI-only, opt-in** distribution pipeline for third-party modules. It does not claim that a signed module is secure: a trusted signing key means the package is from an explicitly trusted publisher and has not been changed since signing. Modules may run arbitrary PHP with the application's privileges after activation. **Review module source and vendor policies before trusting keys or enabling a module.**

## Package format and guarantees

- `.bfp` is a small bounded JSON envelope (`brightery.module.v1`). Its `payload` holds `name`, `version`, and sorted relative file paths with base64 content, and its `signature` holds key ID and Ed25519 detached signature. Requires `ext-sodium`, not PHP's ZIP extension.
- The signed bytes are `BRIGHTERY-MODULE-PACKAGE-V1\n<key-id>\n<canonical-payload-json>` (literal newlines). This binds the key ID and every packaged file, path and manifest to the Ed25519 signature. The package does not ship trusted public keys.
- File paths cannot be absolute, hidden/dot segments, `..`, backslash-separated, or symlinks. File count <= 400, single file <= 4 MiB, decoded payload <= 24 MiB, envelope <= 35 MB.
- The installer re-parses `module.json`, checks the current framework's module API and dependency graph, checks the installed version (no same-version replay/downgrade), and refuses updates to framework-managed `System` and `Api` modules.
- Trust keys are stored in `storage/private/module-trust.json`, never under `public/`. Key IDs are immutable once trusted; rotate by issuing a new key ID. Revocation prevents any **future** verification/install of newly delivered packages under that key, but does not disable already installed PHP code.
- New modules are installed without automatically enabling them. Existing enabled modules remain enabled after updates; use maintenance mode because requests/workers may otherwise load mixed code during the filesystem transition.

## Publisher: generate, sign, distribute

From your CLI in a secure **development** or signing environment:

```bash
php bin/console module:keygen brightery-2026
# Generates storage/private/module-keys/brightery-2026.secret (0600)
# and public key at storage/private/module-keys/brightery-2026.public

php bin/console module:pack Blog brightery-2026 \
  storage/private/module-keys/brightery-2026.secret /tmp/Blog-0.1.0.bfp
```

The example Blog module is optional, and can be packaged for testing. Never commit or deliver `.secret`, `.env`, `APP_KEY`, server credentials, or the trust store. Transfer package and verified signing public key over separate trusted channels. For production publishers, store signing keys offline or in a managed signing service rather than on the web host. The CLI demo generator is not an HSM.

## Operator: trust, preview, install, rollback

```bash
# Public keys are base64 of the raw 32-byte Ed25519 public key.
php bin/console module:key:trust brightery-2026 BASE64_PUBLIC_KEY
php bin/console module:verify /tmp/Blog-0.1.0.bfp
php bin/console module:install /tmp/Blog-0.1.0.bfp
# Dry-run preview only: no files changed.

# Stop request traffic and workers; take a full DB + files backup first.
# When APP_ENV=production, set MODULE_MAINTENANCE=true in .env
# AND independently enable maintenance at the web/load-balancer layer.
php bin/console module:install /tmp/Blog-0.1.0.bfp --apply
php bin/console module:validate
php bin/console module:enable Blog
php tests/run.php

# For an UPDATE to an already-installed module, find the local backup ID:
php bin/console module:backups Blog
php bin/console module:rollback Blog BACKUP_ID
# Preview first; then explicitly restore files:
php bin/console module:rollback Blog BACKUP_ID --apply

# Prevent future packages from a compromised or retired key:
php bin/console module:key:revoke brightery-2026
```

On production, `module:install --apply` and `module:rollback --apply` reject changes unless `MODULE_MAINTENANCE=true` is set. This flag is **not** an HTTP maintenance mechanism: take traffic out of rotation and stop PHP workers/cron separately.

## Upgrade safety / recovery

The installer holds an exclusive local filesystem lock, writes packaged files into an isolated staging directory, preflights dependency compatibility, creates a private copy of any replaced module, and attempts to revert the prior directory on publication failure. Backups live at `storage/private/module-backups/<Name>/<ID>/`. Local file snapshots are versioned, and rollback defaults to dry-run. `module:rollback` checks that the currently installed version still matches the backup it would replace.

**Important limitations:** filesystem publication is not a distributed transaction across multiple servers, requests or PHP-FPM processes. Keep deployments in maintenance, coordinate multi-node releases, restart workers/FPM and clear OPcache as needed. The installer does **not** run module migrations automatically and cannot roll back database DDL or side effects, external services, queues, or scheduled jobs. Test database compatibility before running `migrate`, and restore synchronized DB + private-file backups for full disaster recovery.

The package/signing workflow has unit/smoke regression tests; real staging verification, long-running worker reload, and external security review remain outstanding. Do not trust arbitrary modules simply because their manifests validate.
