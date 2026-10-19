# Brightery Framework v0.4 — operations and developer APIs

## Queue contracts

The queue uses `bf_jobs` (MySQL 8 JSON), atomic UPDATE/unique lease claims, bounded retries with exponential delay, and explicit `completed`/`failed` states. It is **at-least-once delivery**, not exactly-once: handlers must be idempotent. Expired leases can cause the same job to run twice; use external idempotency keys for payment, messaging, and other non-repeatable side effects. Jobs should complete well within the 120-second default lease or be split into smaller units. No job automatically inherits a web user's authority.

Modules register handlers in their `boot.php` or service-provider `boot()`, not by modifying `core/`:

```php
<?php
use Brightery\Core\Container;
use Brightery\Core\Events\Hooks;
use Brightery\Core\Jobs\{Queue,Registry};
use Brightery\Core\Scheduler\Schedule;
return static function(Container $container,Hooks $hooks): void {
    $container->get(Registry::class)->register('example.reconcile',
        static function(array $payload) use($container):void {
            // Validate $payload; perform a short, idempotent job.
            // Tenant context is set only when dispatch originated on an active tenant.
        });
    $container->get(Schedule::class)->everyMinutes('example.reconcile.hourly','example.reconcile',60);
};
```

Within a tenant request, use `$container->get(Queue::class)->dispatch('example.reconcile',['record_id'=>42],delaySeconds:5)`. Context is resolved from the current request — callers cannot inject an arbitrary tenant ID via the standard API. Central maintenance jobs are dispatched from central mode. Per-tenant schedulers need an explicitly audited separate service; do not wrap the global scheduler in a loop with forged tenant contexts.

To operate, run in the deployment directory:

```bash
/usr/bin/php8.4 /absolute/path/to/framework/bin/console migrate
/usr/bin/php8.4 /absolute/path/to/framework/bin/console schedule:list
/usr/bin/php8.4 /absolute/path/to/framework/bin/console schedule:run
/usr/bin/php8.4 /absolute/path/to/framework/bin/console queue:work --max=100
/usr/bin/php8.4 /absolute/path/to/framework/bin/console queue:stats
```

Production: configure a cron entry for `schedule:run` **each minute**, and a supervised CLI worker for queued jobs. For simpler installations, run bounded `queue:work --max=100` via cron every minute with a `flock` lock to avoid excess instances. Configure a separate process or monitoring system to alert on failed jobs, unexpected queue growth, and absent scheduler runs. Do not use a web request as a worker trigger.

## API credentials

Generate a token from an interactive administrator shell (prints raw token only once):

```bash
php bin/console api:token 12 4 integration profile:read,workspace:read 30
php bin/console api:token 1 central profile-only profile:read 30
php bin/console api:revoke 5 12
```

Use `Authorization: Bearer bfpat_<id>_<64-hex-secret>` over HTTPS on the **matching tenant hostname**. Endpoints: `GET /api/v1/me` (`profile:read`); `GET /api/v1/workspace` (`workspace:read` + active membership with `workspace.view`). Tokens are scoped to the user and tenant (or central host), stored as SHA-256 digests, time-limited to 1–365 days and revocable. The API never inherits administrator session privileges. Nginx must forward the Authorization header to PHP-FPM (included in `deploy/nginx.conf.example`). There is no OAuth2, public token-management UI, API version negotiation, CORS layer, or pagination yet.

## MFA

An authenticated user can visit `/account/mfa` on the same host and enroll using a standard TOTP authenticator (6 digits, 30 second step, SHA-1 as required by interoperable RFC 6238). The secret is encrypted with Sodium secretbox under `APP_KEY`. A valid current code activates MFA; subsequent password logins create a 5-minute pending challenge, **not** an authenticated user session. Each TOTP time step can be used at most once. Check password/role membership before granting a session.

There are **no self-service recovery codes or email verification flows yet**. An authorized infrastructure operator with an interactive CLI can run `php bin/console mfa:reset USER_ID`, confirm the exact account email, and re-enroll with out-of-band identity verification. The reset is written to central audit. Keep `APP_KEY` stable and backed up securely; rotation invalidates stored TOTP secrets unless re-encrypted.

## Audit logs

Security-sensitive tenant membership updates and invitation issue/accept operations append audit rows in the same DB transaction. Module activation/deactivation is also logged by the admin controller. Records are scoped by `tenant_id` and read through `AuditTrail::recent()` within an active context; the central dashboard never lists tenant rows. Sensitive keys in metadata are recursively redacted; avoid arbitrary raw request/response dumps and never put credentials in `subject_id`.

Audit is append-only **at the application API level only**; database owners and administrators can edit data. An external append-only SIEM, retention policy, background export, and tamper-evident hash chaining are not implemented.

## Boundaries

Single-tenant deployments use central/nullable operation rows for background work. Multi-tenant deployments must review any custom raw SQL or third-party modules for tenant filters. Jobs are trusted PHP module code and should be audited before module activation. No untrusted module sandbox exists. Comprehensive MySQL, concurrency/load, penetration, browser, and production mail tests remain necessary before production use.
