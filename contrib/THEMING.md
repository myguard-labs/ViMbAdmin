# Theming ViMbAdmin

ViMbAdmin has a built-in **skin** system. A skin can override two things,
independently — you usually only need the first:

1. **Styling** — a single stylesheet, loaded *after* the base theme so it
   wins, with zero markup changes.
2. **Templates** — drop-in `.phtml` overrides for any view, for when you need
   to change the actual HTML (logo, footer, extra blocks).

Whatever you don't override falls through to the stock files, so a skin stays
small and survives upgrades.

## Quick start: a CSS-only skin

1. Pick a name, e.g. `mytheme` (use only `[A-Za-z0-9_-]`).
2. Create the stylesheet:

   ```
   public/css/_skins/mytheme/skin.css
   ```

3. Create the skin's (possibly empty) template directory — the skin loader
   refuses to activate a skin whose template dir is missing:

   ```
   mkdir -p application/views/_skins/mytheme
   ```

4. Enable it in `application/configs/application.ini`:

   ```ini
   resources.smarty.skin = "mytheme"
   ```

That's it. ViMbAdmin checks for `public/css/_skins/<skin>/skin.css` on disk
and, if present, links it last in every page's `<head>` (see
`application/views/header-css.phtml`). If the file is missing, nothing is
linked — no broken `<link>`, no error.

Because it loads after Bootstrap and the stock CSS, your rules win with normal
specificity. Start from the bundled example:

```
public/css/_skins/dark/skin.css
```

— a complete dark theme. Enable it with `resources.smarty.skin = "dark"` to
see the mechanism working, then copy it as a starting point.

## Template overrides (optional)

To change markup, mirror the path under `application/views/_skins/<skin>/`:

```
application/views/_skins/mytheme/header.phtml     # overrides views/header.phtml
application/views/_skins/mytheme/footer.phtml     # overrides views/footer.phtml
application/views/_skins/mytheme/domain/list.phtml
```

The resolver (`OSS_View_Smarty::resolveTemplate()`) checks the skin path
first and falls back to the default. `{tmplinclude}` / `{includeIfExists}`
are skin-aware too, so partials resolve correctly.

Only override what you must — every file you copy is a file you now have to
keep in sync across upgrades.

## Notes

- **One skin at a time.** The skin is global (set in `application.ini`), not
  per-admin. Per-admin theme selection would mean storing a preference and
  setting `___SKIN` from it in the Smarty view bootstrap — not shipped.
- **Minified bundle.** If `config.use_minified_css` is on, the base CSS is
  served as one bundle; your skin stylesheet is still appended after it, so
  overrides keep working.
- **Underlying framework is Bootstrap 2.** Theme against those classes
  (`.navbar-inverse`, `.well`, `.btn-primary`, `.table`, …). The `dark`
  example shows the selectors that matter.
- **Caching.** After changing a `.phtml` override, clear the Smarty compile
  cache (`var/templates_c/`). CSS changes are picked up on reload (bump a
  query string if a proxy caches aggressively).
