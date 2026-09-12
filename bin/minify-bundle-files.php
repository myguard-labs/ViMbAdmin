<?php

/**
 * The explicit, repo-owned bundle input lists.
 *
 * Historically `bin/minify-options.php` handed `vendor/opensolutions/minify/minify.php`
 * a glob (`public/js/[0-9][0-9][0-9]-*.js`) and the vendor script expanded it
 * after `require_once()`ing the config, so there was no post-glob hook and no
 * way to keep a file on disk without shipping it.
 *
 * That is not a hypothetical: Chosen and Colorbox were removed from the
 * application in PR #180 (no `<script>`/`<link>` row, no `.chosen(` call site,
 * no `chzn-*` markup) but their files stayed on disk, unbundled, for a time.
 * The glob matched them anyway, so every regeneration re-shipped both
 * libraries to production AND rewrote `application/views/header-js.phtml` /
 * `header-css.phtml` from the glob, silently reverting PR #180. VIM-A15.56
 * deleted both vendor files, their CSS and their images outright instead of
 * continuing to carry them unbundled, which is why `jsExcluded` /
 * `cssExcluded` are empty below.
 *
 * The fix is this file: the bundle inputs are enumerated here, in the
 * repository, where a reviewer can see them in a diff. `bin/minify-bundle.php`
 * reads them; nothing is discovered.
 *
 * Adding a new asset means adding it here as well as dropping it in
 * `public/js` or `public/css`. That is deliberate -- being on disk is no longer
 * enough to be shipped to every browser.
 *
 * This file must stay free of side effects: `tests/test-minify-bundle-inputs.php`
 * loads it (and `bin/minify-bundle.php`'s resolver) without Java or clean-css
 * installed, which is the only way the exclusion is asserted in CI.
 *
 * Ordering is the concatenation order of the bundle. It matches the numeric
 * filename prefixes, which `minify.php` obtained via `sort( $files, SORT_STRING )`;
 * `bin/minify-bundle.php` re-sorts these lists the same way, so a row added out
 * of order here still lands in the historical position.
 *
 * @return array{js: list<string>, css: list<string>, jsExcluded: list<string>, cssExcluded: list<string>}
 */

return [

    // Shipped in public/js/min.bundle-v<N>.js and listed in the {else} branch
    // of application/views/header-js.phtml.
    'js' => [
        '100-jquery.js',
        '120-vimbadmin.validation.js',
        '150-jquery.datatables.js',
        '151-jquery.datatables.ext.js',
        '152-jquery.datatables.bootstrap5.js',
        '800-bootstrap.js',
        '850-vimbadmin.modals.js',
        '910-vimbadmin.functions.js',
        '990-vimbadmin.js',
    ],

    // Shipped in public/css/min.bundle-v<N>.css and listed in the {else}
    // branch of application/views/header-css.phtml.
    'css' => [
        '800-bootstrap.css',
        '815-bootstrap-icons.css',
        '816-datatables-bootstrap5.css',
        '890-override_container_app.css',
        '895-bootstrap-override.css',
        '920-style.css',
        '930-popup.css',
    ],

    // Assets present in public/js / public/css but deliberately NOT bundled
    // go here, enumerated rather than merely omitted, so bin/minify-bundle.php
    // can tell "deliberately excluded" from "someone forgot to list it" and
    // fail loudly on the latter (see vimbadminResolveBundleInputs() in
    // bin/minify-bundle.php).
    // Kept declared, though empty: bin/minify-bundle.php requires all four
    // keys, and an asset dropped from the application but kept on disk for a
    // time is a recurring situation.
    'jsExcluded' => [
    ],

    'cssExcluded' => [
    ],
];
