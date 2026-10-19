# Form Studio and upgrade-safe form schemas

**Storage**: `storage/private/forms/<slug>.json` with atomic writes and mode 0600. No executable PHP or HTML templates are created from user content. The directory must stay private and survive core upgrades.

Admin → Form Studio supports text, email, number, date, textarea, select, checkbox, file and same-origin AJAX autocomplete. Limit: 40 unique fields per form, restricted field identifiers and option keys, 64 KB request cap. JSON imports are server-validated. File inputs must be processed by the existing private media service; `validateSubmission()` intentionally does not inspect uploaded bytes.

```php
$forms = \Brightery\Core\App::get()->service(\Brightery\Core\Form\FormBlueprint::class);
$definition = $forms->load('contact');
if ($definition === null) throw new RuntimeException('Unknown form');
$html = $forms->render($definition); // escaped, server-rendered HTML fields
$errors = $forms->validateSubmission($definition, $request->post);
if ($errors !== []) return \Brightery\Core\Http\Response::json(['errors'=>$errors],422);
// Application-specific authorization, CSRF, anti-spam, media validation, and business processing follow.
```

The PHP class is deliberately subclassable to allow application replacement via `app/overrides.php`. ControlRegistry's existing registration mechanism is unchanged. Neither rendering nor saving a schema automatically exposes public routes. The designer is a convenience tool, not a complete accessibility or security certification.
