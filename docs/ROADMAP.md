# Roadmap — post v0.2.0

| Phase | Scope | Acceptance criteria |
|---|---|---|
| 0.1 delivered | MVC bootstrap, auth+RBAC starter, EN/AR, admin light/dark UI, media prototype, cache | PHP smoke tests |
| **0.2 delivered** | Service lifetimes/contracts, module dependency DAG/providers, optional multi-tenant host context, isolated tenant data/cache APIs, named/grouped routes, migrations, hybrid UI islands, assets/build manifest | 0.1+0.2 smoke tests; **MySQL integration outstanding** |
| 0.3 | Tenant-specific authentication/memberships/invitations, policy-scoped DB queries, verified domains, MFA, password recovery, comprehensive HTTP middleware | MySQL integration and cross-tenant IDOR suite |
| 0.4 | Advanced media service: server transforms, quotas, temporary signed URLs, chunked uploads, file picker/cropper/reorder | Upload malware checks + UX/accessibility testing |
| 0.5 | Background jobs, scheduler, event bus, Redis atomic locks/rate limits, queue dashboard, cache tags | Multi-worker concurrency and isolation tests |
| 0.6 | Full module language namespaces, translation editor diff/merge/history, XLIFF/CSV, RTL checks | Translation round-trip tests |
| 0.7 | Database schema builder, query builder/ORM with tenant scopes, PSR HTTP interoperability, typed validators, response/ETag caching | Benchmarks + compatibility tests |
| 0.8 | Signed module distribution, installer, semver compatibility tooling, rollback and upgrade dry runs | Signed release and recovery tests |
| 0.9 | Platform telemetry, audit trails, asset pipeline JS AST minification, production CI/CD, security/performance profiling | SAST/DAST, load tests, external review |
| 1.0 | Stable extension API, written semver commitments, complete docs and upgrade matrix | Production-readiness checklist |

Security goal: work toward OWASP ASVS Level 2; do not claim certified compliance without a completed external review.

## v0.3.0 milestone (implemented October 16, 2026)

- Tenant workspace sign-in, active-membership guard, owner/admin/editor/viewer policy, protected role changes, manual one-time invitations and central initial-owner bootstrap.
- New tenant theme, CSS source minification, additive invitation migration, guarded invite API and disposable-MySQL integration test script.
- Still pending before production SaaS: execute database integration suite; enforce scope in all third-party/custom repositories; MFA/recovery/verified-email delivery; centralized audit trails; API authentication; queues; integration/load and security assessments.

## v0.4.0 delivered (2026-10-16)

- Trusted named background jobs with MySQL leases/retries and CLI-only workers; UTC scheduled jobs with unique slots.
- Central and tenant audit viewing, credential-field redaction, automatic logging of tenant membership and invitation changes.
- Scoped, expiring, revocable bearer API credentials bound to user + tenant host.
- Opt-in encrypted TOTP MFA with a second-factor challenge before an authenticated session, plus supervised recovery tooling.
- Continued v0.2 module API compatibility; module/job/schedule extensions remain in dedicated user/module folders.

## v0.5 priorities

- MySQL integration runs, concurrent-worker race tests, idempotent delivery examples, metrics/alerts and process supervision.
- Account email verification, SMTP delivery with queued notifications, MFA backup codes, session/device inventories, password reset flows.
- Optional tenant-aware API rate limiting, audit export and tamper evidence, OAuth2/OpenID Connect integrations.
- Background image processing, signed media uploads, module-specific UI schema controls and form builder, accessibility audit.
- Perf benchmarking with representative apps, query profiling, Redis and production observability.

## v0.5.0 delivered (2026-10-16)

- Authenticated SMTP delivery adapter (TLS only), encrypted queued outbound email and local dev mail sink.
- Account email ownership verification via expiring one-time tenant-bound tokens and CSRF-protected confirmation.
- One-time MFA recovery codes, account-bound revocable session inventory and session revocation UX.
- Database-atomic tenant-specific bearer API quotas.
- MIME-whitelisted tenant-scoped private media storage and basic upload/download UI.

## v0.6.0 priorities

- End-to-end verified-email invitation delivery and verification-required policy, password reset, stronger phishing-resistant account recovery and WebAuthn.
- MySQL 8 concurrency/load evidence; SMTP interoperability suite; media malware scanning, per-tenant quotas, image derivatives and crop/resize/reorder API.
- Faster shared Redis/Lua rate buckets with fail-closed infrastructure fallback, proper proxy/IP allowlist handling.
- Stronger workflow orchestration, job heartbeat and worker idempotency, extended automated module validation and documentation.

## v0.6.0 delivered (2026-10-16)

- [x] Upgrade-safe private image variant service with GD crops/resizes, duplicate transform protection and tenant isolation.
- [x] Enhanced dropzones, file reordering and private media browser support for reusable PHP inputs.
- [x] Trusted ControlRegistry factory extension point and ID/label AJAX autocomplete helper.
- [x] Password recovery with hashed one-use tokens, encrypted email, and session/token revocation.
- [x] Email-queued tenant invitations with domain-bound origins (raw invite URLs no longer shown in UI).
- [ ] **Staging gate:** complete live MySQL 8, GD, browser and SMTP integration; run worker concurrency/load and independent security audit.

## Next v0.7 priorities

Media quotas and async thumbnails, virus-scan adapters, WebAuthn/passkeys, template/email localization, accessible form validation, module package signatures, distributed Redis limiter, and tenant isolation E2E regression suites.

## v0.7.0 delivered (2026-10-16)

- [x] InnoDB tenant-specific storage quotas and additive backfill of existing private-media originals and variants.
- [x] Admin configuration for per-company media limits, and API-side membership enforcement and upload rate limit.
- [x] Queue-backed 320×320 private thumbnails, quota-billed, deduplicated, tenant-aware.
- [x] Optional ClamAV UNIX-socket scanning adapter, with explicit fail-closed required-scan policy and safe default documentation.
- [x] English/Arabic plaintext email templates through an overrideable catalog.
- [x] Disposable MySQL tests for tenant quota accounting and six independent competing upload workers.
- [ ] **Staging gate:** run DB concurrency, GD, clamd, SMTP, UX/accessibility, and external security testing.
- [ ] Future: WebAuthn/passkeys, per-user language preferences, signed media URLs, orphan file/counter reconciliation, robust async media processing and telemetry.


## v0.8.0 delivered (2026-10-16)

- [x] Locally-pinned Ed25519 signed `.bfp` module envelope; no extra ZIP dependency.
- [x] CLI operator key generation/trust/revocation, signing, verification and dry-run module installation.
- [x] Compatibility DAG/version preflight; no auto-enable and no auto-migration; protected framework modules cannot be replaced.
- [x] Private module backup snapshots and explicit file rollback, protected by a local installer lock and version checks.
- [x] Nonmutating default; production requires explicit maintenance flag plus external downtime orchestration.
- [x] Regression tests for tampered/untrusted packages, path traversal, key changes/revocation, deps, upgrades and rollback.
- [ ] Staging gate: live MySQL concurrency, GD/ClamAV/SMTP, multi-node/OPcache updates, interrupted deploy recovery and external security audit.

## Proposed v0.9.0 priorities

Operational reliability: media orphan/counter reconciliation, safe distributed deployment orchestration, async upload observability, tenancy-integrated application tracing, robust per-user locale preferences, and integration/testing automation. Maintain backwards-compatible module API until a reviewed 1.0 stability commitment.

## v0.9.0 delivered (2026-10-17)

- [x] Read-only queue health snapshots and operator dashboard with worker heartbeat lifecycle and stale indicators.
- [x] Additive `008_operations_reliability.sql` for worker heartbeats; worker registry uses random IDs without secrets.
- [x] Deployment preflight + maintenance-gated release finalization under local locks (no code deployment or DDL rollback).
- [x] Media quota reconciliation preview and opt-in repair, serialized with concurrent quota reservations.
- [x] Regression unit/smoke checks and disposable-MySQL scenarios for worker state and media quota isolation.
- [ ] Actually execute MySQL/GD/ClamAV/SMTP/concurrency/performance/security test gates in staging.

## v1.0.0 development milestone (2026-10-17)

- [x] Keep public Module API `0.2.0` independently versioned; add executable compatibility manifest and `compat:check`.
- [x] Privacy-aware structured JSONL logs, server-generated request IDs, latency percentiles and protected Operations summary.
- [x] Upgrade-safe `LoggerInterface` and tests for redaction/no client-supplied request IDs.
- [x] Reproducible GitHub CI configuration with disposable MySQL 8, PHP 8.4, quota-concurrency suite, JS and PHP checks.
- [x] Local-only bounded HTTP concurrency smoke test; lightweight security smoke and microbenchmark.
- [ ] GitHub pipeline must be executed on a configured repository, staging environment and independently audited before production claims.
- [ ] Future: true OpenTelemetry export, WebAuthn, dependency SBOM/security monitoring and coverage reporting.
