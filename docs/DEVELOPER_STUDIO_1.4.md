# Developer Studio and Generated CRUD — 1.4

## Relationship search and selection

When scaffolding a module, add a field of type `relation` with `references` set to an existing generated module name in the **same scope**. The generated module exposes:

- `GET /workspace/{slug}/relations?field=customer_id&q=alex` (tenant, permission `content.read`) or `GET /admin/{slug}/relations?...` (central, source module read permission **and** referenced module read permission).
- JSON response `{ "items": [{ "id": 1, "label": "Example" }] }`; results are limited to 20 and no field/table names are taken from user input. Search uses a normalized installed blueprint, matches first matching `title`, `name`, `label` or other text/email column, and is scoped by `tenant_id` when applicable.
- Browser-side autocomplete debounces and cancels stale fetches, has keyboard navigation and does not inject returned text as HTML. The hidden field transmits only the selected integer ID. Saving validates the reference again on the server and will reject a deleted or foreign-tenant target.

These relation fields are **not database-enforced foreign keys**. If you need ON DELETE/UPDATE behavior, implement and review a separate migration and domain policy.

## CRUD tables and bulk operations

Generated routes include `POST /{scope}/{slug}/bulk-delete`, guarded by the module write permission and CSRF. A user must select 1–50 distinct visible record IDs and type `DELETE`. Every ID is validated inside a transaction, and the entire operation rolls back if any record is invalid or absent in the current tenant. Irreversible deletes are intended only for modules where the domain supports them; customize the generated application controller to implement soft deletion if required.

Listing accepts `page`, `per_page` (1–100), `search` (up to 100 bytes), `filter`, `value`, `sort` (schema column or `id`) and `direction` (`ASC`/`DESC`). All SQL identifiers are server-allowlisted. The current search uses SQL `LOCATE()` and may require indexes/full-text search for large datasets. No feature in this release guarantees indexing at scale.

## Application-owned validation policy

New generated module includes:

```php
namespace Modules\GadgetItems\Policies;
use Brightery\Core\Developer\CrudValidationPolicy;
use InvalidArgumentException;
final class RecordPolicy implements CrudValidationPolicy {
    public function validate(array $schema, array $values, bool $partial): void {
        if (($values['title'] ?? null) === 'Forbidden') {
            throw new InvalidArgumentException('Title unavailable');
        }
    }
}
```

`CrudRepository` performs strict type checks, required values, and tenant-bound relation validation first, then invokes the application policy. Existing module constructors remain compatible with the optional fourth parameter.

## Schema Evolution Studio

At `/admin/developer/evolve`, platform users with `modules.manage` can select an installed generated module, review immutable fields and append new nullable fields. Server normalization and the `ModuleEvolver` digest prevent destructive edits, stale confirmations and unknown schema properties. The studio generates a migration but does **not** apply SQL, re-enable a module, or restart workers. An enabled module cannot be evolved by the web apply route. Keep complete database/file backups and use maintenance gates; MySQL ALTER TABLE actions may not roll back automatically.

## Test and integration commands

```bash
php tests/run.php
php tests/security.php
php bin/console compat:check
# CI/staging only, with isolated disposable DBs, pdo_mysql, GD, and MySQL 8:
bash scripts/ci-verify.sh
```

The generated module code belongs to the application and can be freely customized. Framework upgrades intentionally avoid overwriting it. Older generated modules must be updated manually to adopt new route/view features.
