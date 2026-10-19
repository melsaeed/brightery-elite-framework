# Module Builder · Brightery Framework v1.2.0

## Scope

The module generator creates **application-owned** PHP MVC modules, not modifications to `core/`. The schema is validated and constrained: module names in PascalCase, at most 24 fields, types `text`, `textarea`, `email`, `integer`, `decimal`, `date`, `boolean`, and exactly one data boundary (`tenant` or `central`). The generator deliberately cannot ingest arbitrary PHP, SQL, custom HTML, relation expressions, file types, or remote endpoints.

Outputs under `modules/ModuleName/` are `module.json`, `blueprint.json`, `database/migrations/001_create.sql`, `Models/RecordRepository.php`, `Controllers/CrudController.php`, `routes.php`, and `README.md`. Modules are created **disabled**, and generated SQL is never run by the builder. Files are staged, then published under a lock, with no overwrite of an existing module directory. Generated modules are application-owned and must never be replaced by a framework-only upgrade package.

## Visual builder

Sign into central admin with `modules.manage`, open **Module Builder** at `/admin/developer`, add fields, and choose the scope. Review the file paths and escaped SQL. To publish, type `CREATE ModuleName` and submit. The browser is protected by global CSRF middleware. In `APP_ENV=production`, additionally set `MODULE_BUILDER_MAINTENANCE=true` only for a controlled creation window. Deactivate afterward. Code generation on an internet-reachable server is an operational risk; prefer the CLI and a code review/deployment pipeline for production.

Enable the generated module through Admin → Modules, run `php bin/console migrate`, then reload FPM/workers. Generate on staging first. New migrations may require coordination and a database backup; file rollback **does not roll back schema changes**.

## CLI dry-run and application

Example `catalog.json`:

```json
{
  "name": "Catalog",
  "title": "Product Catalog",
  "scope": "tenant",
  "fields": [
    {"name":"title","label":"Product name","type":"text","required":true},
    {"name":"price","label":"Price","type":"decimal","required":false},
    {"name":"published","label":"Published","type":"boolean","required":true}
  ]
}
```

```bash
php bin/console make:module catalog.json           # JSON preview only
php bin/console make:module catalog.json --apply   # write module files
php bin/console module:validate
php bin/console module:plan Catalog enable --json
php bin/console module:enable Catalog
php bin/console migrate:status
php bin/console migrate
php tests/run.php
```

`--apply` on production additionally requires `MAINTENANCE_MODE=true`. Run PHP CLI as the project/site user with controlled filesystem ownership; the builder must be able to create directories under `modules/`.

## Generated authorization and data boundaries

**Central module** — Admin routes `/admin/<slug>` use dedicated database permissions `module.<slug>.read`/`module.<slug>.write`. The module's migration inserts these permission codes. Assign them through existing Admin → Groups after migration. API bearer tokens need `modules:read` or `modules:write`, **and** the token holder must have the matching central group permission. Central API tokens are accepted only on the central/single app context.

**Tenant module** — Workspace routes `/workspace/<slug>` require tenant membership and `content.read`/`content.write` role permissions. Queries always use `TenantRecords`, which derives `tenant_id` from the request's trusted tenant context. Tenant API calls also verify membership and bearer scopes. IDs provided in requests **never** select the tenant. The same named module can only have one scope per installation; separate modules are needed for separate central and tenant models.

REST API routes: `GET /api/v1/modules/<slug>` (list 50), `POST /api/v1/modules/<slug>` (create), `PATCH /api/v1/modules/<slug>/{id}` (partial update), `DELETE /api/v1/modules/<slug>/{id}`. POST/PATCH use JSON objects, `Content-Type: application/json`, and a 64 KiB request-body maximum. Existing authenticated API rate limits apply. `TokenService` now permits the `modules:read` and `modules:write` scopes.

## Limits and verification

- This is a constrained generator, **not** a full generic ORM, relationship designer, workflow builder, policy editor, or production-ready low-code platform.
- Generated changes are application-owned. They are not automatically rebuilt from the JSON schema, and schema evolution requires a new reviewed migration; running the generator twice under the same name is deliberately blocked.
- Generated API is not publicly accessible without a valid scoped bearer token. Token issuance remains a privileged operator action.
- Existing generated CRUD uses synchronous queries and up to 50 recent records; pagination, search/filtering, event hooks, record-specific authorization, soft deletes, and attachments need follow-on extensions.
- The app-owned modules directory must not be treated as a disposable framework directory. Full framework ZIP installation must not be used to overwrite a running site; use upgrade ZIP plus reviewed backups.
- MySQL-backed generated CRUD and cross-tenant isolation integration tests are included in the disposable CI suite but were **not run in the authoring environment** without `pdo_mysql`. Browser interaction, Nginx/PHP-FPM performance, independent security audits remain staging requirements.
