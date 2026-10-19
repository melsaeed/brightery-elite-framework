# Production acceptance checklist

- [ ] Origin + CDN enforce HTTPS, correct host allowlist, HSTS, and secret-path redaction
- [ ] APP_KEY generated/stored, DEBUG off, secure cookies, revocable sessions, MFA verified
- [ ] Private storage protected; public web root points to `public/` only
- [ ] MySQL disposable integration suite and quota/tenant concurrency suite are green in CI
- [ ] Chromium browser E2E is green; admin, reset, invite, uploads tested
- [ ] Real SMTP / TLS delivery works, no tokens in logs; ClamAV socket scanned test upload
- [ ] Cross-tenant media and relational CRUD isolation verified with concurrent requests
- [ ] OPcache, static asset cache and compression checked under Nginx/PHP-FPM
- [ ] Load tests at expected concurrency meet agreed p95 and failure-rate budget
- [ ] Backups restored successfully to a disposable environment
- [ ] Queues/scheduler supervised; monitoring, alerts, disk quotas and log rotation ready
- [ ] Independent application security review completed and findings resolved
- [ ] `php bin/console production:check --json` reports `ready: true`

**Do not check these boxes based on static tests alone.**
