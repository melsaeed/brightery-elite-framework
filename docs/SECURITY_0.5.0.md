# v0.5 security boundaries and operational cautions

**Not certified/pentested.** This is a design and implementation checklist, not an assertion of vulnerability-free production readiness.

## Boundaries added

- Active authenticated PHP sessions must have a server-side row containing only a SHA-256 digest of the session ID, the correct user and tenant. Revocations are consulted each request; idled and expired records are rejected. PHP cookies remain host-only, HttpOnly and Secure under HTTPS. Existing sessions are intentionally invalidated on registry activation.
- MFA fallback codes are 128-bit random strings, stored as SHA-256 digests, updated with a conditional atomic one-time database write; display once and store offline. Rotation requires a fresh TOTP, not just a session. Emergency CLI reset requires interactive confirmation and revokes account sessions.
- Verification tokens are 256-bit random secrets, SHA-256 digested in the database, scoped to user and tenant, 30-minute TTL and consumed in a database transaction. GET displays confirmation only; POST with session CSRF token consumes. Do not embed token URLs into analytics, Referer headers, logs or third-party widgets.
- Mail outbox body is encrypted with APP_KEY; a queue job references an outbox ID, never a URL token. Recipient metadata is stored in clear for delivery. SMTP enforces peer verification and STARTTLS (or implicit TLS); no plaintext-auth fallback. The local `.eml` logger refuses to operate outside APP_ENV=local.
- Private media has random opaque storage keys; files are outside public/, and the new media controller is gated by permission on central or tenant hosts. Tenant SQL queries filter exact tenant ID and central media only sees `tenant_id IS NULL`. MIME sniffed on the server; SVG, script and executable files rejected. Downloads are `Content-Disposition: attachment` with `nosniff`.
- Authenticated bearer API traffic uses an atomic database-backed fixed-window quota. Each tenant has a separate namespace, preventing a heavy tenant from consuming other tenants' token budgets. API authentication and membership checks still run before the API throttle.

## Remaining critical work

- Full MySQL concurrency tests, fuzzing and end-to-end SMTP provider tests were not executed in the package environment.
- Verify all application-level SQL is tenant-scoped; custom modules can bypass scope when using raw SQL.
- Email verification is not a mandatory login policy yet. Add verified-email invitation issuance, enforcement, resend and recovery policies before making it mandatory.
- `Remote-Addr` is used for existing login rate limits; configure trusted proxy/CDN controls and WAF at the edge. Prevent unauthorized request floods at Nginx/Cloudflare, especially bearer failures which still require DB lookups.
- Full API distributed throttling may benefit from Redis Lua scripts at very large scale. Database quota rows must be pruned through `schedule:run` and queue workers.
- No per-tenant media byte quotas, antivirus/quarantine, malicious PDF scanning, image pixel limits at viewing stage, upload image transformations or streaming large file downloads yet. Current allowed files max 10MB.
- SMTP transport supports plaintext emails only, with authenticated STARTTLS/implicit TLS. Dedicated mailer libraries, DKIM signing, bounce handling, idempotency at SMTP boundary, bulk mailing controls and deliverability dashboards are future work.
- Content-security hardening, SRI, end-to-end security reviews, dependency scanning, pentesting, backups, secrets management and scaling benchmarks still required for production.
