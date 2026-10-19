# Contributing

Until an open-source license is chosen, the public repository is source-available for inspection; contributions are by maintainers' prior agreement only.

Do not include secrets, customer data, media, `vendor/`, or `.env` in changes. Prefer upgrade-safe `app/` and application modules to edits to framework core. Contributions require relevant regression/security tests, documentation, `php -l`, `php tests/run.php`, `php tests/security.php` and CI MySQL tests. Destructive migrations require a separate backup/rollback design.
