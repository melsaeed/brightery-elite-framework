# Brightery Framework v0.5.0 → v0.6.0

**Test in staging first.** Before upgrading production, back up files, storage/media and MySQL, including all existing identities and the application encryption key.

1. Stop queue workers and scheduler and place the application into maintenance mode. Record the deployed v0.5.0 code and DB schema.
2. Unzip `brightery-framework-v0.6.0-upgrade.zip` into the existing **project root** (not `/public`). The archive contains only framework-owned changed/new files; `.env`, `app/overrides.php`, `app/themes/`, custom modules and `storage/` are deliberately excluded. Check local modifications to framework-owned files before overwriting.
3. Merge `.env.example` guidance without overwriting `.env`. Configure `APP_URL` (HTTPS in production), `TRUSTED_HOSTS`, `SESSION_SECURE=true`, `APP_KEY`, SMTP, `MAIL_FROM`, `pdo_mysql`, and `gd` if image processing is required. One-time links need valid delivery and worker execution.
4. Run on staging:

```bash
php bin/build-assets
php bin/console module:validate
php bin/console migrate:status
php bin/console migrate
php bin/console doctor
php tests/run.php
php bin/console route:list
php bin/console queue:stats
```

5. Migration `006_media_password.sql` adds `bf_media_variants` and `bf_password_reset_tokens` (no table drops). It stores deduplicated private image transformations and digest-only recovery tokens. **MySQL DDL is not atomically reversible**: inspect migration status before retrying a failed deployment.
6. Run integration tests against an EMPTY disposable schema named `brightery_test_*`, plus verify actual tenant-domain invitation email, reset-email delivery, TOTP/MFA continuity, user logout after password change, file visibility, cross-tenant access denial and GD crop output.
7. Restart Supervisor workers, scheduler and PHP-FPM, and confirm new assets serve without 404. Check mail and failed queues. Purge cached HTML carefully; never cache `/invite/*`, `/reset-password/*`, `/workspace`, `/admin`, `/account`, or private media responses.

## Behavior changes

- Invitation links are no longer shown on screen; the mail worker is required for delivery.
- Password resets invalidate all previously issued sessions and API tokens for that user, regardless of company.
- New media variants are tenant-scoped private JPEGs; originals keep their existing format and location. The older `/admin/media` still works for compatibility.
- `Kernel::VERSION=0.6.0`; public module API remains `0.2.0`. System module `0.3.3` remains `0.3.x` compatible.
- If MySQL or GD is unavailable, private image transformation cannot execute. Core PHP unit tests alone do not guarantee a safe production deployment.

## Rollback

Stop new workers, restore a **matching** pre-upgrade code/storage and database snapshot, preserve `APP_KEY` and check any emails already queued or sent. Do not delete user-media files or drop tables manually without reconciling references. Any outbound invitation/reset emails dispatched during deployment may remain valid until their database records are revoked or expire.
