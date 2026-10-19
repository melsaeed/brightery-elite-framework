# Brightery Framework 1.3.0 — Release Verification

## Scope

- Same-scope generated-module references stored as relation identifiers and checked against the current tenant context or central scope.
- Search + equality filters + bounded pagination for generated CRUD admin/workspace screens and REST API.
- Append-only `evolve:module` dry-run/apply workflow, plus maintenance-gated `module:migrate-disabled` for execution before reactivation; reviewed migration and existing-schema preservation.
- New test coverage for untrusted field metadata, stale schema digest, impossible/destructive changes and scoped pagination; disposable MySQL tests extend the v1.2 suite.

## Locally executed (PHP 8.4.24, October 2026)

- 283 unit/smoke assertions passed.
- 15 HTTP security smoke checks passed (not a penetration test).
- 146 PHP syntax validations and JavaScript syntax checks passed.
- Module compatibility checker returned no violations; four built-in modules validated.
- Release ZIP integrity and simulated v1.2 → v1.3 upgrade are verified separately before delivery.

## Not executable in build environment

`pdo_mysql`, `gd` and MySQL server are missing here. The test suite contains database integration checks, but **live MySQL relation isolation, generated migrations, concurrency and media/image operations have not been run locally**. No passing CI result is claimed. Run the repository's GitHub Actions integration workflow with MySQL 8 and PHP extensions before production rollout.

## Upgrade warning

Framework release updates do not edit application-owned modules automatically. For any schema evolution, disable the target module, use maintenance mode, back up the database, generate and review the additive migration, then apply it separately and re-enable. MySQL DDL operations are not transactional; automated rollback is not provided. Declarative relations are application-validated but not MySQL-enforced foreign keys.
