# Application visual checks

Run `bash ci/test-browser-visual.sh --evidence-dir /tmp/visual-evidence`
from the checkout. The maintained application fixture boots the real
controllers, templates, stylesheets and locked dependencies against private
MariaDB state. Chromium, Firefox and WebKit each exercise the stock light
configuration and the shipped dark skin. `VIMBADMIN_BROWSER` selects one
engine for the existing CI matrix.

The source-bound inventory retains all 85 action patterns, seven roles and
three skin modes from the original browser inventory. Its verifier compares
current controller actions, skin directories and source digests, and observes
route, role, skin omission and stale-source controls fail before accepting
the restored inventory. Update the inventory deliberately when its bound
sources change; a stale inventory fails rather than silently reducing scope.

The rendered checks cover 25 page/role surfaces at widths 1440, 768 and 375:
authentication and pending two-factor pages; administrator lists/add/password
and two-factor settings; domain lists/add/edit/administrators; mailbox
lists/add/edit/password; alias lists/add; archives; logs; maintenance and queue.
The domain list includes super, assigned and unassigned administrators.
Mailbox settings exercise an actual AJAX dialog, its accessible title,
responsive bounds, keyboard dismissal and restored opener focus.

Assertions check application status and route, stylesheet loading/order,
computed body and heading colors, body text contrast, named navigation and
keyboard expansion, and selected primary action visibility, hit testing and
focus. Desktop surfaces select an explicit rendered control instead of skipping
the checks when the responsive navigation toggle is hidden. Bootstrap collapse
transitions finish before hit testing. Independent private-source mutations hide
and clip an action, hide a desktop administrator action, remove the navigation name
and focusability, remove focusability alone, and omit the dark stylesheet.
Every engine must observe each named assertion fail and both restored skins
pass. PHP opcache is disabled and compiled Smarty templates are invalidated
for every changed source. The working application source is never mutated.

Each report contains public route/role/viewport bindings, computed styles,
stylesheet status, horizontal overflow and visible input controls lacking a
label, accessible-name attribute or title. Screenshots mask password fields,
TOTP QR codes, and code/preformatted blocks that can contain fixture secrets.
No HTML, form values, cookies or tokens are included in the report. Image and
report digests, source timestamps/sizes/digests, the exact container invocation
and exit statuses accompany the evidence. Failed runs retain their reports
and a screenshot of the failing page.

The dark reports enumerate every owned skin selector's matching rendered
elements and computed declared properties, plus button hover styles. This
qualifies the earlier static specificity review: selectors with no matches,
including legacy Bootstrap 2 selectors, have **no runtime proof** in that
surface. A zero match is not evidence that operator template overrides cannot
emit them. The reports retain separate table heading/body and input-group
styles rather than inferring a cascade result from source order.

Passing these assertions is not a blanket visual or accessibility verdict.
Horizontal overflow and missing-label diagnostics are recorded observations,
not silently declared clean; inspect their screenshots and source before
filing individual fixes. Body text contrast does not establish contrast for
every control, hover state or overlaid element. This suite adds no pixel
baseline, screen-reader certification, first-install/reset-token visual
fixture, or coverage of every form/mutation/error state. The `myskin` example,
operator-specific overrides and production deployments retain their original
unverified disposition. Existing auth/error, tenant and operations suites
remain the behavioral coverage for their named routes and roles.
