# Brightery 1.0 — Request Diagnostics and Operational Telemetry

Every HTTP response includes a **server-generated** `X-Request-ID` (128 bits of randomness). Incoming `X-Request-ID` is intentionally not trusted or replayed. `core/Observability/LoggerInterface` enables an upgrade-safe custom sink.

`JsonLogger` writes JSONL to `storage/logs/requests.jsonl`, outside the public document root, with append locks and restrictive permissions. Request completion entries capture UTC timestamp, request ID, method, sanitized URL *path only* (not query strings), HTTP status, duration in milliseconds, and optionally the exception class. **No body, form, cookie, Authorization header, email, IP address, raw token, or full exception text is intentionally collected.** Fields that look like passwords, keys or tokens are redacted recursively and invitation/recovery/verification paths are masked. Avoid logging arbitrary strings that may embed secrets; adapters must follow the same policy.

Example operator commands:

```bash
php bin/console ops:requests --limit=2000
php bin/console ops:status --json
php bin/console compat:check
```

The first command summarizes a **bounded** window of JSONL entries (up to 8 MiB read and 10,000 samples) with response buckets and p50/p95/p99 request time. These are *application* timings, not actual client-network latency or a substitute for load measurements. The protected central Operations screen displays p50/p95 and error counts. Rotate this file with the OS log service, keep it private, and set retention/privacy policies for your users and jurisdiction.

Example logrotate configuration (replace `APP_USER APP_GROUP` with real service-account names):

```text
/path/to/project/storage/logs/requests.jsonl {
    daily
    rotate 14
    missingok
    notifempty
    compress
    create 0600 APP_USER APP_GROUP
}
```

Do not place logs, credentials, or `APP_KEY` under `public/`. Log writer failures are intentionally suppressed in the HTTP response path so they cannot trigger a recursive error. Monitor disk space and permissions separately. When logging is disabled by setting `LOG_LEVEL=critical`, even successful requests do not appear in the summary; exception classes are logged with error severity and will also be filtered out in that mode.
