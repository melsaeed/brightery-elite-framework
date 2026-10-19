# Developer Studio v1.5.0

## What changed

- The Module Builder (`/admin/developer`) supports **opt-in soft deletion** (`"soft_delete": true`) for newly generated modules. It creates `deleted_at TIMESTAMP NULL` and a tenant-aware active-state index. Legacy modules remain hard-delete modules until explicitly migrated/customized by their owner; this upgrade never alters their tables or module blueprints.
- New records are active by default. Deletion marks the row with the current UTC time. Active CRUD listings, single-record reads/updates and bulk deletes hide trashed rows. `?view=trash` and the CSRF-protected `/restore` POST allow users with write permission to restore rows. Central restores additionally require the module-specific write grant. JSON API lists exclude trashed rows and ignore `view=trash`.
- Relationship lookup and field validation for generated modules reject soft-deleted targets. References to existing rows do not cascade on deletion, and generated migrations still do not add database foreign keys.
- Field schema may include `rules` with integer `min_length` / `max_length` for text, textarea, email, or integer `min` / `max` for integer and decimal fields. Length bounds are measured in UTF-8 **bytes** and must not exceed storage limits. Unsupported arbitrary regex or SQL expressions are rejected. Application-owned `Policies/RecordPolicy.php` remains the extension point for complex rules.
- `/admin/developer/diagnostics` and `php bin/console module:diagnose ModuleName` give read-only checks of module files, blueprint, manifest, routes and migrations; they do **not** run user code, SQL, external linters or security scanners.

## Example blueprint

```json
{
  "name": "TaskNotes",
  "title": "Task notes",
  "scope": "tenant",
  "soft_delete": true,
  "fields": [
    {"name":"title","label":"Title","type":"text","required":true,"rules":{"min_length":3,"max_length":120}},
    {"name":"priority","label":"Priority","type":"integer","required":false,"rules":{"min":1,"max":10}}
  ]
}
```

Run `php bin/console make:module path/to/blueprint.json` to preview. After backup, run `... --apply`, verify/activate the module, migrate with maintenance restrictions, and retest. Enabling soft deletion on a preexisting generated module is **not supported** through the append-only visual evolution tool because adding `deleted_at` changes query semantics; use a separately reviewed custom migration and module changes.

## Security and limitations

- All HTTP endpoints inherit existing global CSRF, middleware and module permission guards; the trash view additionally checks write permissions. Tenant record operations derive scope from `ContextStore`, never a request field.
- No permanent-delete/force-delete endpoint is generated. Restored records retain their original identifiers; soft deletion is not a backup strategy, storage encryption policy, or compliance retention solution.
- The filesystem diagnostics only validates known paths and metadata. Installed PHP modules are trusted code, not sandboxed.
- Server-side MySQL and browser coverage is in CI. Local builds without `pdo_mysql` and GD cannot claim database integration or image processing verification.
