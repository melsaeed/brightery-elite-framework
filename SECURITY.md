# Security policy

**Current release status:** 1.6.1 is a hardening candidate, not a production certification.

## Reporting vulnerabilities

Do not open public issues containing exploits, passwords or customer information. Use GitHub's private vulnerability reporting when enabled on the repository, or contact the repository maintainers through a private channel. Include affected version, steps to reproduce, expected vs observed security behavior, and impact. Do not test against production installations without authorization.

## Security model

- Framework and module code execute with PHP process privileges; signed packages do not sandbox code.
- Only `public/` may be internet accessible. Never publish `.env`, `storage`, signing keys, or customer media.
- Modules and generated CRUD must enforce tenant scope and authorization server-side; never trust tenant IDs from clients.
- MFA, CSRF, session revocation, request validation, host allowlisting and security headers are implemented, but not externally audited.
- Maintain patched PHP, MySQL, Nginx, OpenSSL, GD and ClamAV, with backups and tested recovery.

## Support

Security fixes should be assessed and released with impact-specific migration and key-rotation guidance. The supported compatibility line is the public Module API `0.2.0` pending an explicit change announcement.
