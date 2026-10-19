# Security and reliability notes (v0.9.0)

- No new unauthenticated operational endpoints. Admin operations remain behind the existing central `settings.manage` gate; tenant roles cannot visit central operations on tenant domains.
- Diagnostic JSON is available from CLI, not a public route. Worker IDs are random and do not contain PIDs, OS usernames, tokens, customer identifiers, hosts or queue payloads.
- Deployment preflight never prints secrets; the release coordinator uses explicit CLI actions, a private atomic state file, local flock, the existing MySQL migration lock and production maintenance gating. Operators must drain web requests/workers and take backups externally.
- Media reconciliation requires an explicitly selected central/tenant scope; path validation prevents unsafe storage keys. Preview does not change usage counters. Counter repairs require CLI, a maintenance flag and an exact confirmation selector. Row-level locking serializes with the existing media quota reservations. Missing files are reported, not deleted.
- Heartbeats do **not** extend queue leases and `queue:work` remains at-least-once processing; long handlers must renew the job lease and ensure idempotency.
- Production gates unchanged: MySQL integration/concurrency tests, image processing, real external services, application-specific tenant model review, performance load testing and independent security audit.
