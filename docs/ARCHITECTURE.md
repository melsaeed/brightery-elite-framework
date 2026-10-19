# Brightery Framework v0.2.0 architecture

## Fixed choices

- Framework name: **Brightery Framework**.
- Application types: **single-tenant sites and multi-tenant SaaS** (multi-tenant control-plane/auth is still partial).
- UI: **hybrid** — server-rendered PHP views and opt-in vanilla JS islands, no required SPA runtime.
- Stack: PHP 8.4+, MySQL 8+, Nginx/PHP-FPM, optional Redis.

## Request path

`public/index.php` -> `bootstrap/app.php` -> `Kernel` -> trusted host check -> `TenantResolverInterface` -> request-scoped `ContextStore` -> CSRF/security -> route groups/guards -> controller -> PHP View/JSON Response -> security headers -> Nginx gzip and static asset caching -> context/scope reset in `finally`.

With `TENANCY_MODE=single`, `TenantContext(mode=single)` does not require the tenant schema for ordinary routing. With `TENANCY_MODE=multi`, the control plane is selected by a separately configured exact hostname. Tenant domains are fetched from the DB only if active; unknown/suspended hosts return 404. Central `/admin` is unavailable on tenant hosts. Domain ownership verification and automated TLS issuance remain deployment responsibilities.

## Service lifecycle

`Container::bind($id,$factory,$shared=true)` defaults to singleton. `Container::scoped()` caches per request. `Container::bind(...,false)` is transient. `Container::alias()`, `Container::instance()` and `Container::extend()` handle other extension patterns. Services implement their declared interface when a class/interface name is used as the key, preventing invalid replacements.

Core bindings -> module provider **register** (dependency order) -> module provider **boot** -> legacy module `boot.php` -> `app/overrides.php` (application wins) -> module route registration. Static `App::get()` remains a compatibility helper; prefer explicit constructor injection in new code. Never resolve scoped services at provider registration time.

## Upgrade-owned boundaries

| Owner | Paths | Upgrade policy |
| --- | --- | --- |
| Framework | `core/`, `bootstrap/`, `bin/`, `themes/`, `modules/System/`, `public/assets/` | Versioned replacement; read changelog |
| Application | `app/`, `.env`, custom app themes | Preserve across upgrades |
| Module developer | `modules/<Name>/` | Independently version and migrate |
| Deployment | `storage/`, uploaded assets and runtime state | Never overwrite/drop |

The view resolver searches `app/themes/` before built-in `themes/`. Modules may register additional view search paths using `View::addPath()` from providers.

## Module manifest v0.2

```json
{
  "name":"TenantNotes",
  "version":"0.2.0",
  "framework":"^0.2.0",
  "dependencies":{"System": ">=0.1.0"},
  "providers":["Modules\\TenantNotes\\NotesServiceProvider"],
  "required":false
}
```

Supported constraints: `*`, exact `x.y.z`, `^x.y.z`, `>=x.y.z`; not full Composer semver grammar. Code executed by modules is **trusted PHP**. Manifest validation is NOT code-signing, isolation, or a sandbox. Module activation state is written atomically with a dedicated filesystem lock. Disabling active dependencies is rejected. Enabling a new module requires a separate migration command; its disable operation intentionally retains schema and data.

## Tenant isolation contract

`TenantContext(mode=tenant, tenantId)` is available during tenant HTTP execution through `ContextStore`. `TenantRecords` methods require the tenant ID and inject `WHERE tenant_id=?` / inserts with `tenant_id` into the SQL. All tenant resource tables using this helper require `id` and `tenant_id` columns. `TenantCache` prefixes keys by tenant ID. Both throw outside tenant context. Application code that writes raw SQL can still bypass this: developers MUST audit all such paths.

Tenant RBAC, login, invitations, public tenant dashboards, jobs/queues, cross-tenant super-admin impersonation and tenant-specific rate limits are NOT included yet. The `tenant_memberships` table is a forward-compatible schema foundation only.

## Hybrid UI

`IslandRenderer::register('name', fn(array $props):string => '<div>...</div>')` registers server renderers. Calls to `render()` output a named root with escaped JSON props and server markup; `window.BrighteryIslands.register(name,fn)` adds optional client enhancement. Browser scripts should use `textContent`/DOM APIs rather than assigning untrusted HTML to `innerHTML`. The provided `tags` example is a full working pattern; existing image uploader/autocomplete remain available.

`php bin/build-assets` copies fingerprinted CSS/JS content to `public/assets/build/`, writes `public/assets/manifest.json`, and `AssetManager` resolves those URLs. The build scripts do not make unsafe regex assumptions about minifying JavaScript; Nginx handles gzip and immutable caching only for fingerprinted builds.

## Production readiness gaps

An OWASP ASVS L2 review, security headers/CSRF tests under reverse proxies, tenant authorization and IDOR tests, MySQL integration tests, dependency scanning, SSRF/media threats, Redis atomic throttling, signed extension releases, API policies, async workers and benchmarked performance budgets remain open. Do not claim production security or performance guarantees without measured evidence.

## v0.3.0: identity, authority and module API compatibility

`TenantContext` identifies `single|central|tenant`. `TenantAccess` reads a company membership for the authenticated `Security::userId()` and checks `TenantPolicy` on tenant-only routes. Global `Auth::guard` continues to manage only control-plane routes. `TenantMemberships` serializes updates under the tenant row and refuses actor/context mismatches. `TenantInvitations` issues only for a permitted actor and consumes a hash-identified token exactly once within a transaction. Implementations should never accept company IDs from untrusted route inputs when a current context exists.

`ModuleManager::API_VERSION` stays at 0.2.0 even though `Kernel::VERSION` is now 0.3.0. Manifest `framework` constraints apply to the stable module API, and the optional `min_release` constraint applies to the framework product release. This is intentional: avoid unnecessary breaking upgrades for application modules. Upgrade-safe themes also support the new `app/themes/workspace/` overlay.
