# Editing and publishing Brightery Framework on GitHub

You may add, remove, or modify source files normally. There is no static source-file SHA-256 validation step. `RELEASE.json` is informational release metadata; it contains no per-file hashes, and `SHA256SUMS.txt` is deliberately omitted.

## Before publishing

1. Unzip the release at the repository root (not in an extra nested directory).
2. Keep `.env`, `storage/` contents, secret keys, database dumps, and third-party credentials out of Git. Review `.gitignore` and `git status` before each commit.
3. Edit source, documentation, and application-owned modules as needed. Git will record changes and compute its own internal object hashes.
4. When you edit files under `public/assets/css/` or `public/assets/js/`, run `php bin/build-assets`, then commit the newly generated `public/assets/manifest.json` and `/public/assets/build/` files along with your source edits.
5. Run `php tests/run.php`, `php tests/security.php`, `php tests/production.php`, `php bin/console compat:check` and `php bin/console module:validate`. Follow `docs/PRODUCTION_CHECKLIST.md` for live MySQL, browser, mail, scanner, and tenant-isolation checks.
6. Commit and push to your public repository only after reviewing the files for secrets and confirming licensing.

## What must still be verified

- Git history tracks changed source files and needs no hand-maintained checksum inventory.
- Frontend assets use *generated* fingerprints for cache busting. Rebuild them after changes.
- `.bfp` packages use Ed25519 signatures for authentic module installation. Never bypass this verification. Repackage and re-sign a changed package with a trusted publisher key.
- Optional archive SHA-256 values are useful for immutable official release downloads, but are not required for normal GitHub commits. If distributing an immutable release archive, calculate its checksum from the finalized archive, not an earlier draft.

**Release status:** v1.6.1 is a production-hardening candidate, not yet independently certified for production customer data.
