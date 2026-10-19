# Security model — v0.6.0

**Status: development foundation; independent review required.** The framework does not claim complete OWASP ASVS compliance or production certification.

## Boundaries and defensive controls

- Tenant authorization applies at the admin/workspace routes and to private media metadata/variants. The variant lookup matches the requesting tenant on both the parent and child rows; no public static URL exposes private storage.
- Uploads reject symlinks and empty/oversized files, sniff MIME server-side (JPEG/PNG/WebP/PDF), use randomized storage keys, and write outside `/public`. This is not equivalent to malware scanning. Reject unsupported image codecs and cap decoded pixel dimensions before GD decoding.
- Image transformation options are a strict allowlist; output is re-encoded as JPEG to strip original metadata. Externally supplied filenames are not used in storage paths.
- Password-reset and invitation tokens are 256-bit random secrets. The database stores SHA-256 digests, not plaintext; outgoing messages use the authenticated-encryption outbox (secret `APP_KEY`). Email queue payload carries only numeric message ID.
- Password-reset tokens are tenant-bound, expire in 20 minutes, and are consumed with row locking. Successful reset changes Argon2id hash and invalidates **all sessions and API tokens** of that identity; it does not log the requester in.
- Invitation delivery accepts only an origin associated with an actual tenant domain, requires role authorization, and queues mail in the same transaction as the invitation record. UI does not expose raw links.
- CSRF is checked on state-changing HTML form requests; HTTP routes are guarded by central RBAC or tenant permissions. Rate limits apply to password requests, attempts and private transforms. Additional CDN/IP abuse controls are still needed.

## Operations requirements

Enforce HTTPS everywhere, secure cookies, tightly configured trusted hosts, and proxy access-log redaction for `/invite/*`, `/reset-password/*`, `/verify-email/*`. Secure `.env` and `APP_KEY` at rest. Deploy uploads outside the document root, disable directory listing, and keep the encryption key for rollback. Configure TLS-verified SMTP and monitor failed job queues.

## Known gaps

Tenant-aware hard quotas, antivirus scanners, EXIF orientation handling, color-profile normalization, robust user-input/a11y/browser testing, worker concurrency stress testing, DB integration in CI, trusted proxy IP validation, MFA phishing resistance, and end-to-end pentesting remain outstanding. PHP extensions `pdo_mysql` and `gd` must be available on staging to verify those branches.
