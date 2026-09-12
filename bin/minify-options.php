#!/usr/bin/env php
<?php
 /**
  * A JS and CSS minifier for projects using the Smarty PHP templating engine
  *
  * Released under the BSD License.
  *
  * Copyright (c) 2010 - 2012, Open Source Solutions Limited, Dublin, Ireland <http://www.opensolutions.ie>.
  * All rights reserved.
  *
  * Redistribution and use in source and binary forms, with or without modification, are permitted
  * provided that the following conditions are met:
  *
  *  - Redistributions of source code must retain the above copyright notice, this list of
  *    conditions and the following disclaimer.
  *  - Redistributions in binary form must reproduce the above copyright notice, this list
  *    of conditions and the following disclaimer in the documentation and/or other materials
  *    provided with the distribution.
  *
  * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS
  * OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
  * MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL
  * THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL,
  * EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE
  * GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
  * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
  * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED
  * OF THE POSSIBILITY OF SUCH DAMAGE.
  */


  /**
   * This file contains configurable options for the bundle build. It is loaded by
   * bin/minify-bundle.php, the repo-owned driver that replaced the vendor
   * minify.php entry point.
   */

// Regenerating a bundle needs Closure Compiler at bin/compiler.jar. It is a
// per-machine build prerequisite rather than a vendored 13MB binary:
//
//   curl -fsSL -o bin/compiler.jar \
//     https://repo1.maven.org/maven2/com/google/javascript/closure-compiler/v20230802/closure-compiler-v20230802.jar
//   printf '%s  %s\n' \
//     230a9e05a8a7d9daa083b1f6e86edba6eb1ec6402a6a258432fe4245cdc4a95f \
//     bin/compiler.jar | sha256sum -c -
//
// Verify the digest before Java runs the file: the bundle it emits is shipped
// to every browser, so an unverified compiler is a supply-chain hole.
//
// Minifying the CSS bundle additionally needs the clean-css CLI, installed
// project-locally (the vendored yuicompressor.jar corrupts Bootstrap 5 CSS --
// see the CSS Configuration section below for what exactly it breaks):
//
//   npm ci --prefix bin
//
// Both are per-machine build prerequisites; the binaries are gitignored, while
// bin/package.json and bin/package-lock.json pin the complete clean-css graph.
//
// Run it as:
//
//   php bin/minify-bundle.php --version 18
//
// bin/minify-bundle.php is the repo-owned driver and the ONLY supported entry
// point. Do NOT invoke vendor/opensolutions/minify/minify.php directly: it
// expands the $js_files / $css_files globs below only after require_once()ing
// this file, so there is no post-glob hook and every NNN-prefixed file on disk
// is bundled AND written into the header .phtml files -- which re-ships the
// dead Chosen and Colorbox assets and silently reverts PR #180. The driver
// substitutes the explicit list in bin/minify-bundle-files.php for the glob and
// reproduces everything else this file configures.
//
// One trap survives: --version takes the bare number ('18'), because the 'v'
// prefix is added for you. The driver does honour --js-only and --css-only
// (this file resets $whatToCompress below, which is why they never worked
// through the vendor entry point; the driver re-applies the command line after
// loading it).

// By default, compress both JS and CSS - can be over ridden by the command line
$whatToCompress = 'all';

// by default, be quiet
$verbose = true;

// We use APPLICATION_PATH as per the Zend framework. Feel free to remove as it's only used for the paths defined below here
defined( 'APPLICATION_PATH' ) || define( 'APPLICATION_PATH', realpath( __DIR__ . '/../application' ) );
// bin/minify-bundle.php defines this for normal use; keep the config loadable on
// its own as well (for validation and custom runners).
defined( 'SCRIPTDIR' ) || define( 'SCRIPTDIR', __DIR__ );


/////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////
//
// JS Configuration
// --language_in ECMASCRIPT_2018: Bootstrap 5's bundle is written in modern
// JavaScript. Arrow functions and template literals need ECMASCRIPT_2015 and
// its object-literal spread needs ECMASCRIPT_2018, so anything lower makes the
// compiler fail to parse `public/js/800-bootstrap.js` even in WHITESPACE_ONLY
// mode. This also accepts the ES6 target of DataTables 3 and the first-party
// browser APIs. The output is tested in Chromium, Firefox and WebKit.
// NB: SCRIPTDIR is the vendor script's OWN directory
// (vendor/opensolutions/minify), not this config file's. The per-machine build
// prerequisites live next to this file in bin/, so they are anchored on __DIR__.
// --charset UTF-8: Closure Compiler's own default is "accept UTF-8 as input,
// emit US-ASCII (with \uXXXX escapes) as output". That silently mangles any
// non-ASCII byte a vendored source legitimately carries -- e.g. the "©" in
// 150-datatables.js's licence header -- into `?` once concatenated through PHP's
// escapeshellarg()/exec() pipeline (VIM-A15.60). Setting --charset explicitly
// makes both directions UTF-8, so those bytes survive unmodified into
// min.bundle-v<N>.js.
$js_compiler = "java -jar " . escapeshellarg( __DIR__ . '/compiler.jar' ) . " --compilation_level WHITESPACE_ONLY --warning_level QUIET --language_in ECMASCRIPT_2018 --charset UTF-8";

$compiler_jar = __DIR__ . '/compiler.jar';
$compiler_sha256 = '230a9e05a8a7d9daa083b1f6e86edba6eb1ec6402a6a258432fe4245cdc4a95f';
$actual_compiler_sha256 = is_file( $compiler_jar ) ? hash_file( 'sha256', $compiler_jar ) : false;
if( $actual_compiler_sha256 !== $compiler_sha256 )
{
    fwrite( STDERR,
        "FATAL: Closure Compiler digest mismatch for {$compiler_jar}.\n" .
        "       Expected SHA-256: {$compiler_sha256}\n" .
        "       Actual SHA-256: " . ( $actual_compiler_sha256 === false ? 'missing or unreadable' : $actual_compiler_sha256 ) . "\n" );
    exit( 1 );
}


// JavaScript files to compress.
//
// NOTE: this glob no longer selects the bundle. bin/minify-bundle.php takes its
// inputs from the explicit list in bin/minify-bundle-files.php and uses a glob
// only to reconcile that list against the tree, so an asset that is in neither
// the bundled nor the excluded list is rejected instead of shipped. The
// variable is retained because the vendor entry point requires it to exist.
$js_files = APPLICATION_PATH . '/../public/js/[0-9][0-9][0-9]-*.js';

// stick the files here
$js_dest = APPLICATION_PATH . '/../public/js';

// http reference as to where to find your JS files. We have a defined Smarty
// function called {genUrl} which builds up the URL and takes account of http/s
// automatically. You can just as easily put '/myapp/js/' here for example
$http_js = '{genUrl}/js';

// In our application, we define a var as 0 or 1 where 1 means use the bundle and 0
// means use the individual uncompressed files. I.e. production vs development. The
// script then spits out a Smarty (in this case) template file we can include which
// is aware of the var and uses the individual files or the bundle as appropriate.
//
// For this, we need the components of an if/else clause. I.e.
//
// if( use bundle )
//    <include bundle file>
// else
//    <include original file1>
//    <include original file2>
//    ....
// endif
//
// For Smarty, the follow works so long as you set $config.use_minified_js

// jQuery Migrate (public/js/jquery-migrate-3.5.2.js) used to be hand-appended
// here as a dev-only <script> row after the glob-generated rows, so it loaded
// in development but never shipped in the production bundle. jQuery was
// upgraded to 4.0.0 (VIM-A15.29) and the Migrate shim, written for the 1.9-3.x
// upgrade path, was deleted along with it -- there is nothing left to hand-append.
$mini_js_conditional_if   = '{if isset( $config.use_minified_js ) and $config.use_minified_js}';
$mini_js_conditional_else = '{else}';
$mini_js_conditional_end  = '{/if}';

//
// set the following to false to not use this functionality and maintain it yourself

// $js_header_file = false;

$js_header_file = APPLICATION_PATH . '/views/header-js.phtml';

// We create a minified version of each JS file found. These can safely be deleted:
$del_mini_js = true;

// do we want to keep older minified JS files? If you have old installs taking JS/CSS
// from a CDN / central repository you may want to keep these and delete manually
$del_old_js_bundles = true;





/////////////////////////////////////////////////////////////////////////////////
/////////////////////////////////////////////////////////////////////////////////
//
// CSS Configuration
// The vendored yuicompressor.jar (2013) CANNOT minify Bootstrap 5's CSS. It
// corrupts it in two ways, both silently:
//
//   - it strips whitespace inside `data:image/svg+xml` URIs, turning
//     `viewBox='0 0 16 16'` into the invalid `viewBox='001616'`, which blanks
//     every inline Bootstrap 5 icon (dropdown caret, checkbox and radio marks,
//     form-switch knob, .btn-close, navbar toggler, accordion chevron,
//     carousel arrows);
//   - it rewrites `@keyframes name{0%{...}` to the invalid
//     `@keyframes name{0{...}`, so browsers discard the whole rule and the
//     progress-bar-stripes and spinner-grow animations stop.
//
// clean-css is used instead. Like Closure Compiler above it is a per-machine
// build prerequisite rather than a vendored binary, installed project-locally
// so a global npm install is never required:
//
//   npm ci --prefix bin
//
// Its CLI takes the same `-o <output> <input>` form that
// the build driver already invokes, so no change is needed there.
//
// If it is missing we fail loudly rather than falling back to yuicompressor:
// a silent fallback would reintroduce exactly the corruption above with no
// build-time signal, and the broken bundle ships to every browser.
$cleancss_bin = __DIR__ . '/node_modules/.bin/cleancss';

// realpath() is the single check: it resolves the path and returns false if it
// does not exist, so it doubles as the existence test without a second stat
// that could disagree with it.
$cleancss_real = realpath( $cleancss_bin );

if( $cleancss_real === false || !is_file( $cleancss_real ) || !is_executable( $cleancss_real ) )
{
    fwrite( STDERR,
        "FATAL: clean-css CLI not found at {$cleancss_bin}.\n" .
        "       Bootstrap 5 CSS cannot be minified by the vendored yuicompressor.jar.\n" .
        "       Install it with: npm ci --prefix bin\n" );
    exit( 1 );
}

$cleancss_lock = json_decode( (string) @file_get_contents( __DIR__ . '/package-lock.json' ), true );
$cleancss_installed_lock = json_decode( (string) @file_get_contents( __DIR__ . '/node_modules/.package-lock.json' ), true );
$pinned_packages = is_array( $cleancss_lock ) && isset( $cleancss_lock['packages'] ) && is_array( $cleancss_lock['packages'] )
    ? $cleancss_lock['packages'] : null;
$installed_packages = is_array( $cleancss_installed_lock ) && isset( $cleancss_installed_lock['packages'] ) && is_array( $cleancss_installed_lock['packages'] )
    ? $cleancss_installed_lock['packages'] : null;
if( is_array( $pinned_packages ) )
    unset( $pinned_packages[''] );

$graph_matches = is_array( $pinned_packages ) && is_array( $installed_packages );
if( $graph_matches )
{
    foreach( $installed_packages as $path => $package )
    {
        if( !isset( $pinned_packages[$path] ) || $pinned_packages[$path] != $package )
        {
            $graph_matches = false;
            break;
        }
    }
    foreach( array_diff_key( $pinned_packages, $installed_packages ) as $package )
    {
        // npm omits OS-specific optional packages (notably fsevents on Linux).
        if( !is_array( $package ) || ( $package['optional'] ?? false ) !== true )
        {
            $graph_matches = false;
            break;
        }
    }
}

if( !$graph_matches )
{
    fwrite( STDERR,
        "FATAL: installed clean-css dependency graph does not match bin/package-lock.json.\n" .
        "       Restore it with: npm ci --prefix bin\n" );
    exit( 1 );
}

// -O2 is clean-css's structural optimisation level; --format keep-breaks keeps
// one rule per line so the shipped bundle stays diffable and reviewable.
$css_compiler = escapeshellarg( $cleancss_real ) . ' -O2 --format keep-breaks';

// CSS files to compress. As with $js_files above, this glob no longer selects
// the bundle: bin/minify-bundle-files.php does, and the glob is only the
// reconciliation set.
$css_files = APPLICATION_PATH . '/../public/css/[0-9][0-9][0-9]-*.css';

// stick the files here
$css_dest = APPLICATION_PATH . '/../public/css';

// http reference as to where to find your CSS files. We have a defined Smarty
// function called {genUrl} which builds up the URL and takes account of http/s
// automatically. You can just as easily put '/myapp/js/' here for example
$http_css = '{genUrl}/css';

// See $mini_js_conditional_ above for an explanation

// The skin-override stylesheet is a hand-written block that must survive
// every regeneration: it is unconditional (applies after the bundle/dev
// {if}/{else}), loaded last so it wins, and gated at runtime on $skinCss
// rather than on $config.use_minified_css. See
// application/views/_skins/dark and src/Kernel/Bootstrap.php::skinCss. It is
// folded into $mini_css_conditional_end (mirroring the JS-side Migrate
// technique above) so bin/minify-bundle.php reproduces it instead of silently
// dropping it.
$mini_css_conditional_if   = '{if isset( $config.use_minified_css ) and $config.use_minified_css}';
$mini_css_conditional_else = '{else}';
$mini_css_conditional_end  = '{/if}

{* Skin override stylesheet, loaded last so it wins. Enable via
   resources.smarty.skin in application.ini + drop the file at
   public/css/_skins/<skin>/skin.css. See contrib/THEMING.md. *}
{if isset( $skinCss ) && $skinCss}
    <link rel="stylesheet" type="text/css" href="{$skinCss|escape}" />
{/if}';

//
// set the following to false to not use this functionality and maintain it yourself

// $css_header_file = false;

$css_header_file = APPLICATION_PATH . '/views/header-css.phtml';

// We create a minified version of each CSS file found. These can safely be deleted:
$del_mini_css = true;

// do we want to keep older minified CSS files? If you have old installs taking JS/CSS
// from a CDN / central repository you may want to keep these and delete manually
$del_old_css_bundles = true;
