# Module Builder and Schema Evolution · v1.3.0

This release is **additive** to v1.2 and preserves the public Module API (`0.2.0`). It does not alter existing application module code automatically.

## Declarative relationships

Newly generated modules may declare a field of type `relation` with `references` equal to the **PascalCase name of an already generated module**. Example:

```json
{"name":"Orders","title":"Orders","scope":"tenant","fields":[{"name":"order_number","label":"Order number","type":"text","required":true},{"name":"catalog_id","label":"Product","type":"relation","required":false,"references":"Catalog"}]}
```

- The referenced module must exist under `modules/<Name>/blueprint.json`; the target is validated with `ModuleBlueprint::fromFile`. A relation between `central` and `tenant` scopes is rejected, and a module cannot reference itself in this release.
- Relationships store a `BIGINT UNSIGNED` identifier. The CRUD repository checks that the referenced record is present **in the currently resolved tenant** (or in the central table for central modules). It rejects user-provided `tenant_id` columns.
- The generated form offers a scoped dropdown of up to **100 recent** referenced rows, plus the selected row when editing. It is **not** an unlimited AJAX selector yet. For larger referenced datasets, create a custom module UI or extend the field control.
- The generator does **not** create MySQL foreign-key constraints or delete cascades. Referential integrity is application-validated, not transactionally enforced, so concurrent deletion/reassignment remains a design risk. Use custom DB constraints and policies if strict integrity is required.

## Search and pagination

Generated admin and workspace tables support keyword search over declared `text`, `textarea`, and `email` fields, exact field/value filters, and bounded paging. The API uses the same tenant-scoped query builder and returns `{data,total,page,per_page,pages}`.

Query keys: `page` (1–10000), `per_page` (1–100, defaults to 20), `search` (max 100 bytes), `filter` (a declared data field name), and `value` (exact scalar value). SQL column names come solely from validated blueprints, never arbitrary client input. There are no free-form SQL predicates.

## Append-only evolution

`evolve:module` reads the existing module blueprint and accepts an updated JSON blueprint that has **exactly the same existing fields in the same order**, followed by one or more new **optional** fields. Identity, title and tenant boundary must not change. New nullable SQL columns preserve existing rows.

```bash
# Review first: no file is changed
php bin/console evolve:module Orders /secure/path/orders-expanded.json

# After backup, disable the module and enable maintenance mode in production
php bin/console module:disable Orders
php bin/console evolve:module Orders /secure/path/orders-expanded.json --apply

# Review the generated ALTER TABLE statements; then run migrations
php bin/console module:migrate-disabled Orders
php bin/console module:migrate-disabled Orders --apply
php bin/console module:enable Orders
php bin/console module:validate
php tests/run.php
```

The CLI refuses to apply evolution while the module is enabled, and requires `MAINTENANCE_MODE=true` in production. The review digest includes the original blueprint, proposed blueprint and migration; an old review fails after any intervening change. The generator locks concurrent generation, writes a **new** migration (e.g., `002_add_<hash>.sql`) and changes only `blueprint.json`. It never overwrites controllers, models, routes or previous migrations.

MySQL DDL is **not transactional**. A failure or interruption while applying multiple `ALTER TABLE` statements may require manual recovery. Take a full database backup, deploy under maintenance, and rehearse on staging. Rollback is not automatic. Do not drop, rename, reorder, change types or require an existing nullable field with this command.

## Security boundaries and compatibility

- Do not derive foreign relation fields or tenant IDs from input. Module schemas use a strict whitelist; the tenant ID comes from the active domain/request context.
- A disabled module must be migrated with `module:migrate-disabled NAME --apply` before reenabling to avoid missing-column errors. Ordinary `migrate` only discovers enabled modules.
- This release adds no framework-level migration; only newly generated application modules/evolutions create database migrations.
- Tests include generator hardening and evolution unit checks; live MySQL tests for real tenancy, relations, pagination and post-evolution data are shipped for CI but must pass on staging before deployment.
