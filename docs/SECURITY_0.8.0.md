# Security posture — Brightery Framework v0.8.0

The new signed-module functionality uses libsodium Ed25519 detached signatures with local key pinning, package-size and path bounds, exact module-name validation, an existing dependency-DAG validator, and a no-side-effects default dry-run installer. ZIP deserialization and remote downloads are intentionally absent.

## Threat model

- **Remote package attacker:** cannot install a forged/tampered `.bfp` without a locally trusted signing key, assuming Ed25519 verification and host access controls hold.
- **Compromised publisher or module:** signature does not prevent malicious PHP. Operator must conduct source review, enforce least privilege at OS/network levels, and treat modules as trusted executable code.
- **Path traversal or filesystem links:** incoming package paths and staged output are constrained. Existing filesystem trees are checked for symlinks before backing them up. Deploy CLI access should be restricted to the trusted site operator.
- **Concurrent requests:** module replacements have a local lock and recovery rename, but are **not** safe for live multi-node updates without external maintenance/orchestration.
- **Stolen private signing key:** revoke the pinned public key, rotate to a new key ID, review installed modules and audit access. Package signing keys should not remain on shared PHP-FPM hosts in production.
- **Rollback:** restores module files only. Schema migrations, DB state, external effects, custom queues, and storage changes are explicitly outside its scope.

## Acceptance checks still required

- Live MySQL 8 tenant isolation, upload quota and multi-worker concurrency tests.
- GD media transforms, clamd scanner behavior under timeouts, SMTP integration and HTML/browser UX accessibility audits.
- Multi-server deploy rehearsals with OPcache and long-running workers, interrupted publication/recovery, restricted key management and filesystem permissions.
- SAST/DAST, supply-chain review of installed modules and external ASVS-oriented penetration assessment before production claims.
