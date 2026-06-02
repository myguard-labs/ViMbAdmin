# Skin: dark — template overrides

This directory is the *template* half of the `dark` skin. Drop any view
template here to override the matching default in `application/views/` for
this skin only; everything you don't override falls through to the default.

The `dark` skin's styling lives in `public/css/_skins/dark/skin.css` and is
loaded automatically (see `contrib/THEMING.md`). Most skins only need the
CSS — you only need template overrides here if you want to change markup
(e.g. a different logo in `header.phtml` or a custom `footer.phtml`).
