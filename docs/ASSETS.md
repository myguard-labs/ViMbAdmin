# Browser assets

DataTables core and its Bootstrap 5 JavaScript/CSS integration are pinned to
3.0.3, released 31 August 2026. These are unmodified files from the official
[release directory](https://cdn.datatables.net/3.0.3/), verified 12 September 2026.
No commercial extensions or external runtime requests are needed.

- `public/js/150-datatables.js`: upstream `js/dataTables.js`.
  SHA-256:
  `dd3a93d478a57278f4fe629674c5760222b1e2c5ec96511c7675a5034f249b38`
- `public/js/152-datatables.bootstrap5.js`:
  upstream `js/dataTables.bootstrap5.js`. SHA-256:
  `cb335f90908b20599ec84d5396940f3ecbb958d43b231e58fb2ddb7fa11b63d3`
- `public/css/816-datatables-bootstrap5.css`:
  upstream `css/dataTables.bootstrap5.css`. SHA-256:
  `92a010aa4be02fb5de612cad3aeefc67cdd18ba24529767b9625e60dc70d0c8e`

`151-datatables.ext.js` registers the existing numeric-HTML ordering contract
through `DataTable.ext.type.order`. It replaces the duplicated legacy plugin
registration; there are no other shipped DataTables extensions. The original
numeric-HTML sorter was written by Allan Jardine, SpryMedia Ltd.

DataTables and its integrations use the
[MIT licence](https://datatables.net/license/mit).
Copyright (C) 2008-2026, SpryMedia Ltd.

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies
of the Software, and to permit persons to whom the Software is furnished to
do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.

## Packaging and compatibility

Install the exact input list in `bin/minify-bundle-files.php` and regenerate
both bundles with `php bin/minify-bundle.php --version 29`. The compiler and
CSS tool pins and manual compiler verification commands are documented in
`bin/minify-options.php`; the build does not enforce those tool pins.
Ship the regenerated headers together with both bundles. Remove the old
`100-jquery.js`, `150-jquery.datatables.js`, `151-jquery.datatables.ext.js`
and `152-jquery.datatables.bootstrap5.js` files when updating an existing
installation. No CDN fallback is used; CSP and nonce handling are unchanged.

The source/minified regression lanes run with neither `window.jQuery` nor
`window.$` present. DataTables 3 and Bootstrap have optional interoperability
code that detects an existing jQuery instance; keeping these dormant paths
preserves pristine, independently verifiable vendor files. They neither load
jQuery nor require it, and first-party code never invokes that interoperability.

DataTables 3 uses camelCase settings (`language.emptyTable`, `serverMethod`)
and native events. Consumers can cancel an `xhr` failure with
`event.preventDefault()` or by returning `false` from an `Api.on()` listener.
The server-side request remains `draw/start/length/search/order/columns` with
bracket-encoded nested keys; responses remain
`draw/recordsTotal/recordsFiltered/data`. Short-search suppression, raw contains
sigils, Unicode character counting and error technical-note numbers remain
unchanged.

Entity decoding uses an inert textarea: entities become text, while literal
markup remains literal and cannot create elements or execute handlers.
