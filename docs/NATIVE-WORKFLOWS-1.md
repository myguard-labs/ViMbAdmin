# Native workflow contract, version 1

Contract identifier: `native-workflows/1`, exposed as
`ViMbAdmin_Version::NATIVE_WORKFLOW_CONTRACT`. This documents the breaking
workflow removals in the native kernel relative to the **4.0.0 tag** at commit
`a0c7a20a376ff3aa25d071068ab3f33054009594`. It applies to this native build and
subsequent builds carrying that contract identifier. It does not change the
application release number or database schema version.

Both the legacy tag and subsequent native builds report application version
`4.0.0`; that number alone does **not** establish workflow compatibility.
Builds before this identifier was added must be checked by commit and source.
This contract records existing removals; it does not restore those features.
A later change to any listed workflow must update this contract identifier
and publish migration instructions for the new version.

## Removed workflows and operator replacements

### `login`: remember-me login

Legacy `/auth/login` offered `rememberme` when
`resources.auth.oss.rememberme.*` was configured. Cookies `aval` and `bval`
restored identity from a RememberMe record. Native v1 has no remember-me field,
token creation, or cookie restoration. Old cookies and posted `rememberme`
values do not authenticate or bypass password/2FA checks.

Sign in again when the ordinary session expires. Remove obsolete remember-me
configuration from site overrides. Clear legacy `aval`/`bval` cookies at the
old installation's cookie scope when retiring it. Session configuration still
controls ordinary PHP sessions; increasing cookie lifetime does not restore
token-based login.

### `setup`: initial administrator welcome

Legacy `/auth/setup` automatically emailed the initial administrator's username
and plaintext password after creation; its form had no welcome-email checkbox.
Native v1 creates the account and initializes the schema without that message.
Record the credentials during setup and retain them securely; do not wait for a
confirmation email.

### `admin-add`: new administrator welcome

Legacy `/admin/add` offered `welcome_email` to send the new credentials. Native
v1 has neither the field nor its delivery branch. Convey the account name
separately and arrange a secure credential handoff or use the configured
password-recovery workflow.

### `admin-password`: administrator password email

Legacy `/admin/password/aid/<id>` offered `email` when a super administrator
changed **another** administrator's password, sending the plaintext new
password. Native v1 omits that checkbox and delivery branch. Arrange a secure
credential handoff or let the administrator use password recovery.

Self-service password changes still require the current password and
confirmation; the legacy self-service form already lacked this checkbox.

### `mailbox-add`: new mailbox welcome and CC

Legacy `/mailbox/add` offered `welcome_email` to send welcome/settings text and
the new password; `cc_welcome_email` optionally copied that message to another
address. Native v1 omits both fields and their delivery branches. Hand off the
initial credentials securely. Use the mailbox's **Email Settings** action
separately for connection settings.

### `mailbox-edit`: welcome and CC after editing

Legacy `/mailbox/edit/mid/<id>` and `/mailbox/add/mid/<id>` used a shared form
that retained `welcome_email` and `cc_welcome_email`, allowing another
welcome/settings message. Native v1 omits the fields and welcome delivery. The
add-with-id entry redirects to the native edit route. Use **Email Settings**
separately after editing.

### `mailbox-password`: mailbox password email

Legacy `/mailbox/password/mid/<id>` offered `email` to deliver the plaintext new
password. Native v1 omits the checkbox and delivery branch. The password still
changes after normal authorization and validation. Convey the replacement
password securely; **Email Settings** does not include it.

### `alias-add`: AdditionalInfo fields on creation

Legacy `/alias/add`, with AdditionalInfo enabled and
`vimbadmin_plugins.AdditionalInfo.alias.elements.<name>` configured, created a
`pluginsf_AdditionalInfo` subform with `plugin_additionalInfo_<name>` fields.
The plugin validated and persisted them as `xpiInfo.<name>` preferences.
Native v1 has no alias AdditionalInfo fields, validation, or preference
writeback. The plugin's alias form-processing and preflush handlers are absent.

Keep a backup of existing alias preferences and manage this metadata outside
the native UI. There is no supported native alias-metadata editor in v1. If
that editor is required, postpone migration until a compatible extension exists.

### `alias-edit`: AdditionalInfo fields when editing

Legacy `/alias/edit/alid/<id>` and `/alias/add/alid/<id>` prefilled, validated,
and saved alias preferences using the same AdditionalInfo configuration.
Native v1 retains existing preferences when editing destinations, but does not
display or edit them. The add-with-id entry redirects to the native edit route.
Preserve existing data in backups; submitting old plugin fields does not update
it. The replacement is the same as for alias creation.

## Unsupported submissions and retained data

Removed POST fields are **unsupported and ignored**, including malformed values;
the base action can still succeed when its supported fields and CSRF token are
valid. A success redirect confirms the account/alias mutation only, not a sent
email or saved alias metadata. Clients must stop submitting those fields. A CC
address without the welcome option does not request delivery either.

No data deletion is part of this contract. The retained RememberMe entity/table
does not imply native login support; old records cannot restore a native
identity. Retained alias preferences remain available for backup and a future
adapter, but neither enabling AdditionalInfo nor supplying its old alias
configuration restores the editor.

## Contracts that remain supported

The native mail boundary is `ViMbAdmin\Kernel\Mail\Mailer`. SMTP configuration
still serves password recovery and `/mailbox/email-settings/mid/<id>`. Settings
mail can target the mailbox, its alternative email, or explicit other
recipients, and contains connection settings, **not a new password or welcome
message**. Outgoing mail remains suppressed in demo mode.

AdditionalInfo **mailbox** fields remain supported through
`ViMbAdmin_Plugin_MailboxFormExtension` and `FormPluginHost`, using
`vimbadmin_plugins.AdditionalInfo.elements.<name>`. That mailbox contract does
not extend to aliases. Enabled observers still receive the supported
`alias_add_addPostflush` mutation notification; this is not an alias form hook.
Authentication remains session-based, with the existing password, CSRF,
brute-force, and second-factor boundaries.

## Executable differential coverage

Run `php -d opcache.enable_cli=0 tests/test-native-workflow-contract.php`.
The fixed legacy oracle is recorded from the tag above; the test runs the
actual native GET/POST controllers, forms, services, mailer and plugins against
in-memory persistence and transport spies, without loading the removed
framework or contacting a mail server/database. It checks every workflow above,
opt-in/off and malformed obsolete inputs, invalid CSRF/base fields, and the
still-supported settings-mail and mailbox-plugin paths as positive controls.
The normal PHP unit CI discovers this test automatically.
