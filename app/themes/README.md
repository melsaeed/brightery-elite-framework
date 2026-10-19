Place custom templates here, for example `app/themes/admin/dashboard.php` or `app/themes/frontend/home.php`.
The `View` service searches `app/themes/` before the framework's `themes/` folder.
Never edit framework-owned themes to customize your app: overrides survive framework-only updates.

## Tenant workspace theme

Files under `app/themes/workspace/` override `themes/workspace/` without touching core.
For example, copy `themes/workspace/layout.php` into `app/themes/workspace/layout.php`, then customize your layout there.
