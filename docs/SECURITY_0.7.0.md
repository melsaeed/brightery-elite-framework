# Brightery Framework v0.7.0 — Media / Mail Security Notes

## Data isolation and quota

- Central media uses `scope_id=0`, each tenant uses its numeric `tenant_id`, and the DB record continues to enforce `tenant_id` in reads, deletes and image variants. The media upload service now rejects users without a membership in the active tenant context. The controller applies additional route permissions and upload rate limiting.
- `MediaQuota::reserve()` requires an open DB transaction and locks the scope counter (`SELECT ... FOR UPDATE`). It must be used **in the transaction inserting the media/variant record**. `release()` is similarly transaction-bound to the matching deletion. Storage files are removed after commit. This prevents counters being incremented on SQL failure, but a host crash after SQL commit and before unlink can orphan bytes on disk. Operational reconciliation/cleanup is still needed.
- Uploads copy/move into random 256-bit storage names outside `public`, with restrictive filesystem permissions. Before storing, require MIME sniffing/size/image sanity check and run the scanner adapter. These validations are not a substitute for malware detection.
- The `bf_media_usage` counters include original bytes and derived JPEG bytes. Limits below current usage block *new* writes rather than deleting existing files. The default file-count cap applies to originals only. Existing untracked legacy public assets are out of scope.
- **Never trust user-provided tenant_id** to set context. HTTP routing resolves the company by the exact allowed host; queue worker reconstructs context only from an active tenant DB row. Modules must use tenant-scoped repository APIs and authorization guards.

## Antivirus

- `MEDIA_SCAN_DRIVER=disabled`: **NO ANTIVIRUS CHECK IS PERFORMED**, unless `MEDIA_SCAN_REQUIRED=true`, in which case all uploads fail closed.
- `MEDIA_SCAN_DRIVER=clamav`: stream bounded file contents via clamd `zINSTREAM` UNIX-socket protocol, reject all detections and unknown/time-out/error verdicts. The socket is configured via `CLAMAV_SOCKET`; no shell execution, external network fetches or client-controlled socket path.
- The scanner interface is DI-bound and can be replaced in `app/overrides.php` with a properly authenticated/verified commercial scanner adapter. Scanning is synchronous before DB insertion; no provisional unscanned download route exists.
- Verify daemon health, signature updates, permissions, memory limits and endpoint responsiveness independently; no live clamd integration ran in the build environment.

## Background thumbnails

- The trusted handler `system.media.thumbnail` receives only a numeric media ID, not a filesystem path. Workers reconstruct tenant context, and `ImageVariants::create` authorizes media by context and locks the parent record before changing the DB. Duplicate dimensions are guarded by a unique constraint. The output is a private JPEG and is quota-billed; upload enqueues thumbnail job within the same DB transaction as the media insert.
- Thumbnail job handlers should be idempotent. Very large image decoding can exhaust memory even when dimensions are bounded; enforce PHP worker memory/time limits. GD was not available in the build environment, and none of its JPEG output paths were executed.
- Queue failures and quota exhaustion can leave a missing thumbnail. Originals remain accessible; monitor `queue:stats` and retry policy. No signed public URLs are enabled.

## Localized email

- The `TemplateCatalog` reads fixed `resources/mail/{en,ar}.json` files; identifiers and locale have allowlist syntax; missing variables fail closed. Template interpolation is **plaintext**, never executable PHP, and the SMTP adapter encodes UTF-8 Subject headers while TLS is required for transport.
- Email verification, password reset, and tenant invitations now use the same catalog. Per-user language preference is not available yet: the deployment `MAIL_LOCALE` (falling back to `APP_LOCALE`) sets the locale. Generic responses and one-time hashed tokens remain as in previous releases.

## Remaining release blockers

**Not verified in this build:** MySQL 8 transaction/concurrency tests (missing `pdo_mysql`), image codec testing (missing `gd`), clamd scanning integration, SMTP delivery, browser keyboard/accessibility, penetration testing, signed releases, performance/load testing, or production monitoring. Do not claim ASVS certification. Apply independent review before storing real customer files.
