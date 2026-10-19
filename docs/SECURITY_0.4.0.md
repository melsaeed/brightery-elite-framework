# v0.4 security model and known limits

## Added controls

- 256-bit random bearer token secrets, digest-at-rest, expiry and revocation; tenant scope plus active membership required on every request.
- JSON `/api/v1/*` bearer authentication in Kernel **before** controller dispatch; only authenticated bearer requests bypass cookie-oriented CSRF checks. All cookie form requests still require CSRF.
- Encrypted TOTP authenticator secrets via Sodium; limited-time pending MFA session and single-use counters to block step replay.
- Account and membership revocation rechecked on MFA completion and tenant API requests.
- UTC database queue leases, bounded retries, disabled/suspended-tenant refusal, context cleanup between jobs, and CLI-only workers.
- Audit metadata key redaction and transactionally recorded membership/invitation changes.
- SQL placeholders for untrusted values, explicit permission checks and login rate limiting inherited from v0.3.

## Remaining security work

Not a penetration-tested production security certification. A high-assurance release should add recovery codes, WebAuthn/passkeys, security-key enforcement for privileged roles, a challenge-level MFA rate limiter distributed across hosts, device/session inventories, email verification and authenticated SMTP invitation delivery, API abuse rate limits and structured scopes, audit export to tamper-evident storage, file/media tenant enforcement, backup-and-restore rehearsals, session invalidation on password changes, SAST/DAST/OWASP ASVS reviews, and MySQL integration plus concurrency testing. Do not claim any of these are implemented in v0.4.

The database queue is at-least-once and can duplicate side effects after lease expiry. Job code must be short, trusted and idempotent. Core service bindings and module providers can run arbitrary PHP: only install trusted modules. Tenant scoping is not enforced on raw SQL written by custom application modules.
