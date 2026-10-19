# Developer Studio v1.6 — Many-to-many relationships and grid actions

## Declarative schema

```json
{
  "name": "Articles",
  "title": "Articles",
  "scope": "tenant",
  "soft_delete": true,
  "fields": [
    {"name": "title", "label": "Title", "type": "text", "required": true},
    {"name": "tags", "label": "Tags", "type": "many_to_many", "references": "Tags", "required": false}
  ]
}
```

Create and migrate the target `Tags` module first, using the **same** `tenant` or `central` scope. This is not a free-form SQL relation: the referenced module must have an installed valid blueprint, and all join-table names are derived by the core. Required many-to-many relationships and per-field numeric length rules are not supported. Join tables are named by deterministic SHA-256 based identifiers (`bf_link_...`, max 40 bytes) and enforce referential integrity with `ON DELETE CASCADE`; tenant context still must be checked by application code on every operation.

## Record editing and relationship safety

The UI uses an AJAX-backed search picker with removable chips (up to 50 IDs), backed by `POST` form fields `tags[]`. The server validates exact integer IDs, duplicates, collection sizes, the currently resolved tenant and active/soft-deleted status. Record creation, updates and join-table replacement are atomic. Updates lock the source row. A partial API PATCH that omits `tags` leaves them unchanged; an explicit empty array detaches all tags. No client-supplied `tenant_id` is accepted. Central-module references require the caller to have read permission on the target module.

## Data grid

Newly generated modules have protected `GET .../export` (CSV of first 100 filtered active records) and, when soft deletion is enabled, `POST .../bulk-restore` (up to 50 records per atomic action). Export uses `fputcsv` and prefixes risky leading spreadsheet-formula characters. Export does **not** include virtual many-to-many fields. `id`, scalar fields, search, exact-value filters, and allowlisted sorts are preserved. Many-to-many identifiers cannot be sorted or filtered as if they were real source columns.

## Schema evolution

`evolve:module` generates new join-table DDL when **appending optional** many-to-many fields. It rejects modifying, dropping, or reordering existing fields. Its review digest prevents stale approvals, but MySQL DDL is not automatically reversible. Apply migrations while disabled and restart only after checking them.

## Upgrade-safe application logic

All generated PHP lives in `modules/<ModuleName>/` and remains application-owned. Customize `Policies/RecordPolicy.php` for additional business rules. The framework does not regenerate or overwrite these files during upgrades. New default views and endpoints will only appear for newly generated modules unless explicitly ported.
