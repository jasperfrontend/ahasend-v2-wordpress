# Architecture

How the v2 plugin is built, and why. FINDINGS.md holds the API research; this file holds the
decisions made on top of it.

Plugin: **Xolution MailDelivery**, slug `xolution-maildelivery`, in the `xolution-maildelivery/`
folder of this repo. Namespace `Xolution\MailDelivery`, option and table prefix `xmd_`.

## Context

- Runs on 600+ managed WordPress sites, all managed through MainWP. Sensitive data (API keys) never
  goes into MainWP.
- Requirements: **WordPress 7.0+, PHP 8.4+.** No Composer dependencies at runtime.
- Public GitHub repo. Versions start at **v0.0.1**. Nothing account-specific (account ID, sending
  domain, user names, keys) ever lives in the code or the repo: all of it is a setting.
- **v0.0.1 targets one website.** v0.0.2 adds updates from GitHub releases, v0.0.9 WP-CLI.
- README.md explains setting the plugin up yourself.

## Decisions (2026-11-10)

1. **Selective takeover.** AhaSend only sends email that an integration has claimed. Everything else
   goes through WordPress's normal mailer, untouched.
2. **No fallback.** A claimed email that AhaSend can't accept fails. It is not handed to the normal
   mailer: that mailer is the unreliable path this plugin exists to replace, and an email split
   between two delivery paths is harder to trace. The failure is logged, fires `wp_mail_failed`,
   and `wp_mail()` returns `false`.
3. **Integrations are modular, email types are toggled one by one.** Every email type is its own
   on/off switch. All switches are **off by default**: installing the plugin changes nothing until a
   type is switched on.
4. **Content is sent as the source wrote it.** WordPress Core emails go out as `text_content`
   exactly as core builds them. The integration, not a global filter, decides the content type, so
   another plugin forcing `text/html` through `wp_mail_content_type` cannot mangle a claimed email.
5. **The API key is stored encrypted in the database**, never in `wp-config.php`.
6. **One API key per server**, with only the `messages:send:{domain}` scope for the sending domain
   and locked to the server's IP through AhaSend's IP allow list. A stolen key can only send mail,
   and only from that server.
7. **From address per site:** `<site-host>@mail-delivery.<sending-domain>`, e.g.
   `client-site.nl@mail-delivery.example.com`, so AhaSend's stats show which site sent what.
   Verified on the dev site on 2026-11-10: a Slack test alert arrived in #status (v0.0.8).

Verified in the sandbox on 2026-11-10: AhaSend accepts dots in the local part, including several
   (`shop.client-site.co.uk@…`). The `theirdomain-com` form is not needed.
8. **The plugin is cloaked.** Clients have administrator accounts; only the plugin's managers see it.
9. **Local log, 7 days**, matching AhaSend's own retention. Metadata only, no message content.
10. **Updates come from GitHub releases**, installed automatically.

## How a takeover works

```
source (core, Shield, ...)          wp_mail()                         pre_wp_mail (ours)
   │                                   │                                   │
   │ integration hooks the source's    │ core runs the `wp_mail` filter,   │ marker present and that
   │ own filter and adds a marker      │ then `pre_wp_mail`                │ type enabled?
   │ header:                           │                                   │  no  → strip marker,
   │ X-MailDelivery-Takeover:          │                                   │        return null:
   │   wp-core/password-reset          │                                   │        core mails normally
   └──────────────────────────────────►└──────────────────────────────────►│  yes → send through AhaSend,
                                                                           │        log, return true/false
```

- **Claiming happens at the source.** For the password reset that is
  `retrieve_password_notification_email`, which hands over the full mail arguments. The integration
  adds the marker header there. The marker travels with the email's own arguments, so there is no
  shared state between integrations and nothing can race.
- **One dispatcher sends everything**, on `pre_wp_mail`. It only acts on a marker whose email type
  is registered *and* switched on. A late `wp_mail` filter strips any other marker, so it never
  reaches the normal mailer's headers.
  A plugin that adds the header itself can't force mail through AhaSend.
- **The marker becomes an AhaSend tag** (`tags: ["wp-core/password-reset"]`), so emails can be
  filtered by type in the AhaSend dashboard.
- Because `pre_wp_mail` runs before core applies `wp_mail_from`, `wp_mail_from_name` and
  `wp_mail_content_type`, the dispatcher sets From itself (decision 7) and takes the content type
  from the integration (decision 4).

### Integration contract

```php
interface Integration {
    public function id(): string;            // 'wp-core'
    public function label(): string;         // 'WordPress Core'
    public function isAvailable(): bool;     // e.g. Shield: is Shield active?
    /** @return list<EmailType> */
    public function emailTypes(): array;
}

interface EmailType {
    public function id(): string;            // 'password-reset' → marker 'wp-core/password-reset'
    public function label(): string;
    public function description(): string;
    public function isImplemented(): bool;   // shown but not switchable while false
    public function contentType(): string;   // 'text/plain' | 'text/html'
    public function register(string $marker): void; // hooks the source, which calls Claim::mark($atts, $marker)
}
```

`register()` only runs for types that are switched on.

### WordPress Core integration

Hooks checked against WordPress 7.1.4. All implemented since v0.0.7 (password reset since v0.0.1).

| Email type | Marker | Claimed through |
|---|---|---|
| Password reset | `wp-core/password-reset` | `retrieve_password_notification_email` |
| New user: login details (to the user) | `wp-core/new-user` | `wp_new_user_notification_email` |
| New user registration (to the site admin) | `wp-core/new-user-admin` | `wp_new_user_notification_email_admin` |
| Password changed (to the user) | `wp-core/password-changed` | `password_change_email` |
| Password reset by a user (to the site admin) | `wp-core/password-changed-admin` | `wp_password_change_notification_email` |
| Email address changed (to the old address) | `wp-core/email-changed` | `email_change_email` |
| Email change confirmation (to the new address) | `wp-core/email-change-confirm` | `new_user_email_content` + match |
| Admin email change confirmation (to the new address) | `wp-core/admin-email-change` | `new_admin_email_content` + match |

The first six filters hand over the full mail arguments, so they use one generic class,
`FilteredEmailType`, which adds the marker. The two confirmations only filter the text; core fills
in the link afterwards and calls `wp_mail()` without headers. Their filter does pass the new
address and the hash that ends up in the link, so `EmailChangeConfirmation` claims the next
`wp_mail()` to exactly that address containing exactly that hash. One-shot, like Shield's 2FA email.

### Shield Security integration (since v0.0.5)

Available when Shield is active. The email type that has to arrive: **Email 2FA login**
("Two-Factor Login Verification", with the auto-login link when "Enable Auto-Login Links" is on).
That is how Shield's "magic link" login works: Email Authentication enforced for the roles, plus
auto-login links. Shield has no email-only (passwordless) login; its passwordless option is passkeys.

Shield sends it with a bare `wp_mail()` that has no filter of its own (checked against 22.3.4), so
it is claimed by matching what Shield announces right before it:

1. action `shield/2fa/email/pre_send_email/nonce_verified` gives the user (recipient);
2. filter `shield/2fa_email_otp` gives the code, which Shield's template always prints;
3. the next `wp_mail()` to exactly that address containing exactly that code is claimed. One-shot.

Shield's emails are HTML, and Shield adds the plain-text part through PHPMailer, which this plugin
bypasses. So HTML email types are sent with `html_content` plus a `text_content` made by
`HtmlToText` (links kept as visible URLs). Shield's "SureSend" service, if ever switched on, sends
2FA email outside `wp_mail()`; this plugin can't claim it then.

Since v0.0.6 also **Email sending verification**: the one-time email Shield sends before Email
Authentication can be switched on. It tests the route the 2FA emails will take, so it takes that
route too. Shield announces nothing before it, but its verify link is a Shield action URL with
`ex=email_send_verify` (next to `shield_action`), which no other email contains; that is the claim.

Since v0.0.16 also Shield's **instant alerts, reports and security notices** (14 types, see the
README). Shield sends all of them through one mailer, `EmailCon::sendVO()`, which calls a bare
`wp_mail()`: no hook before it, and nothing in the content to match on like a code or a link. What
tells them apart is the method that called `sendVO()` (checked against 22.3.4):

| Email | Caller of `sendVO()` | Also checks |
|---|---|---|
| The seven instant alerts | `InstantAlertsCon::sendAlert($handler, $data)` | `$handler` is `AlertHandlerShieldDeactivated`, `…CloakedPlugins`, `…Admins`, `…AdminLogin`, `…FirewallBlock`, `…Vulnerabilities` or `…FileLocker` |
| Alert / info report | `ReportGenerator::sendNotificationEmail($report)` | `$report->type` is `alt` / `nfo` |
| Login notice | `UserSessionHandler::sendLoginNotifications()` | |
| Backup login code used | `BackupCodes::sendBackupCodeUsedEmail()` | |
| IP unblock link | `AutoUnblockMagicLink::processEmailSend()` | |
| Security Admin removal confirmation / removed | `RemoveSecAdmin::sendConfirmationEmail()` / `sendNotificationEmail()` | |

`ShieldMailerEmailType` finds `EmailCon::sendVO` in `debug_backtrace()` at `wp_mail` and claims
the email when the frame right above it (the direct caller, so never an outer one) is that method
and its arguments match. One generic class, like `FilteredEmailType` for core. If Shield renames a
method, its email no longer matches and goes through the normal mailer again: nothing is lost,
but recheck these names against each new major Shield version.

Not covered: Shield's license emails ("Pro License Has Been Deactivated" and similar, to the Report
Email), which are about Shield's own account.

Admin login, firewall blocks, Shield deactivated and cloaked plugins are sent right away, one
email per event. Admin accounts, vulnerabilities and FileLocker changes are collected and sent
about 30 seconds later from a WP-Cron event, so those are claimed in a cron request.

### Gravity Forms integration (since v0.0.17)

Available when Gravity Forms is active. Hooks checked against Gravity Forms 3.2.2. Three email
types, decided with Jasper on 2026-11-12:

| Email type | Marker | Claimed through |
|---|---|---|
| Form notifications | `gravityforms/notifications` | `gform_pre_send_email`, every event except the two below |
| Save and Continue link | `gravityforms/save-and-continue` | `gform_pre_send_email`, events `form_saved` and `form_save_email_requested` |
| Entry note emailed | `gravityforms/entry-note` | `wp_mail()` called directly by `GFEntryDetail::lead_detail_page()` |

- **One switch for all forms**, not one per form or a GF "notification service": the marker is the
  same on every site (WP-CLI/MainWP), and a service would show this plugin in each notification's
  settings, past the cloak.
- **Every notification goes through `GFCommon::send_email()`**: submissions, payments, resends,
  the REST API and background processing. It hands the finished email (to, subject, message,
  headers keyed by name, attachments, `abort_email`) plus the notification to
  `gform_pre_send_email`. The claim runs last on it (`PHP_INT_MAX`) and leaves an aborted email
  alone. Save and Continue emails are ordinary notifications in GF 3.x; the old
  `GFCommon::send_resume_link()` has no callers left but is covered (it has no notification).
- **Per email, not per type** (`PerEmailOptions`): the content type follows the notification's
  format, the From Name is the notification's, and the recipients are To + CC + BCC, each once.
  The address stays `<site-host>@<sending-domain>` (decision 7).
- **CC and BCC: one copy each.** An exception to FINDINGS decision 2, because a notification's BCC
  is a real recipient. Each copy is still one email to one person, and nobody sees the others.
  The addresses are only read at send time; `to` and the Cc/Bcc headers are left as GF built them,
  so a BCC address can never end up visible in a To header.
- **Reply-To.** The From Email (often the site admin, or the visitor's address through a merge tag)
  becomes the Reply-To when the notification has none, because replies to the sending address
  would go nowhere useful. Same for the entry note: replies reach the admin who wrote it.
- **Multipart** notifications arrive as a body GF built by hand around `GFCommon::$email_boundary`.
  The claim keeps the HTML part (AhaSend gets a text version through `HtmlToText`). A body that
  can't be taken apart is not claimed, so it goes through the normal mailer intact.
- **Entry notes stay as GF writes them.** GF notes "WordPress successfully passed the notification
  email…" or "…was unable to send…" from `wp_mail()`'s result, which is ours. AhaSend's error is not
  added to the note (Gravity SMTP does add its own log reference): client admins read entry notes,
  and the plugin is cloaked. The failure is in this plugin's log and Slack alert instead.

Gravity SMTP (2.3.4) was studied for comparison: it replaces `wp_mail()` itself and only uses
`gform_pre_send_email` and `gform_notification_note` to link its log to the entry.

### Add-ons (since v0.0.18)

An add-on is a separate plugin that adds integrations. It adds them on the filter
`xolution_maildelivery_integrations`, which runs once, on `init` at priority 1, when the plugin object is
built, so the add-on hooks it as soon as its main file loads. The built-in integrations come first and can't
be replaced or removed: of two integrations with the same ID, only the first counts. From there an add-on's
integration is like any other: its switches in the settings and in `wp maildelivery`, its markers in the
log and in Slack alerts. In the settings it gets a "PRO" badge and a light cyan background
(`Registry::isAddon()`), because add-ons are what clients pay extra for.

What an add-on may use, versioned as `Xolution\MailDelivery\ADDON_API` (now 1; raised only when a change
breaks an add-on, so check it before relying on anything below):

- the filters `xolution_maildelivery_integrations` and `xolution_maildelivery_cloaked_plugins` (a list of
  plugin basenames to hide from non-managers, like this plugin itself);
- `Integration`, `GroupsEmailTypes`, `EmailTypeGroup`, `EmailType` and `PerEmailOptions`;
- `Mail\Claim::mark()` and `find()`, `Mail\Headers::toLines()`, and `Mail\Address`.

An add-on loads its own text domain inside its filter callback: the labels are translated when the plugin
object is built. Without the core plugin, an add-on must do nothing at all, so its source's emails keep
going through the normal mailer.

## Sending

Every request: `POST /v2/accounts/{account_id}/messages`, Bearer key, and

- exactly one recipient (FINDINGS decisions 2 and 4: one request per To address, CC/BCC dropped;
  for Gravity Forms one request per To, CC and BCC address, see below);
- `tracking: { "open": false, "click": false }`;
- `retention: { "metadata": 7, "data": 7 }` (since v0.0.13). **Decision changed on 2026-11-11:**
  content is kept 7 days on purpose, because support can then read the exact email a user received.
  This replaces FINDINGS decision 6 ("AhaSend keeps no copy"). Login and reset links in stored
  emails are single-use or expire within a day, but while valid anyone with AhaSend account access
  can read them, so that access stays limited. History: the original `data: 0` turned out to need
  a Pro/Max plan plus AhaSend's approval (without it AhaSend silently uses the account default);
  v0.0.1-v0.0.12 sent `data: 1`. Setting: `Sender::DATA_RETENTION_DAYS`;
- `tags: ["<integration>/<email-type>"]`;
- an `Idempotency-Key` generated once per address per `wp_mail()` call.

Success means HTTP 202 **and** the single item in `data` has status `queued` or `scheduled`.

**Timeout and retry.** Timeout 10 s. One automatic retry, with the same idempotency key, on a
network error, `429` or `5xx`, waiting for `Retry-After` up to 3 s, with a 5 s timeout. Only a first
attempt that failed within 3 s is retried, so a timeout is not: a user is waiting on wp-login.php,
and a logged-out visitor can trigger password reset emails, so one email never holds a PHP worker for
much more than 10 s. No retry on `400`, `401`, `403`, `422`.

Found in testing: **AhaSend replays error responses for an idempotency key too.** A `403` sent
with key K comes back again for every later request with K (and the same body gives `422` if
changed). So a key must never be reused across separate `wp_mail()` calls: after fixing a
configuration error, the next attempt must use a fresh key. Generating keys per call does that.

## Credentials

- Encrypted with libsodium (`sodium_crypto_secretbox`, built into PHP 8.4). The encryption key is
  derived from the salts in `wp-config.php`, so a database dump, backup or SQL injection does not
  reveal the API key. Someone who can read `wp-config.php` too, or run PHP on the site, can still
  decrypt it: the site has to be able to decrypt it to send.
- If the salts change (security plugins and hosts sometimes rotate them), decryption fails. The
  plugin detects that and shows managers a "re-enter the API key" notice; claimed emails fail and
  are logged meanwhile.
- If `wp-config.php` defines no salts (WordPress then keeps them in the database), the settings page
  warns that the encryption adds nothing on this site.
- The key is never shown again after saving: only `aha-sk-…` plus its last four characters. Keys
  that don't start with `aha-sk-` are rejected with an explanation (v1 keys don't work on v2).
- Options are stored with autoload off.

## Cloaking and managers

- The plugin keeps a list of **managers** (user IDs, not user names, so no name lives in code or in
  the repo). The user who activates the plugin becomes the first manager; with MainWP that is the
  account MainWP's child plugin acts as.
- Only managers see the plugin in the Plugins list, its settings page, its log page and its admin
  notices. For everyone else it is filtered out of the Plugins list and its pages don't exist. Every
  page and form handler checks manager status, not just the menu.
- Managers can add and remove managers. When no listed manager exists any more (deleted, or no longer
  an administrator), every administrator counts as a manager, so the plugin can always be recovered.
- Not watertight, and not meant to be: an administrator can always run code. It keeps the plugin out
  of sight and out of reach of normal clicking.
- Add-ons can ask to be hidden the same way, with the filter `xolution_maildelivery_cloaked_plugins`.
- To verify: that MainWP still sees the plugin for updates and its plugin list while it is cloaked.

## Local log

- Its own table, `{prefix}xmd_log`: time (UTC), integration, email type, recipient, subject,
  status, HTTP status, AhaSend message ID, error message. No body, no headers, so no login links.
- Entries older than **7 days** are deleted by a daily cron job.
- Shown to managers on a log page, newest first, filterable by status and email type.
- Recipient addresses are personal data; 7 days keeps that minimal.

## Translations (since v0.0.12)

All WordPress-facing text goes through the `xolution-maildelivery` text domain, loaded on `init`
before the plugin object is built (it translates the email-type labels). Dutch (`nl_NL`) ships with
the plugin as `.po` plus the compiled `.mo` and `.l10n.php` (WordPress 6.5+ prefers the latter).
`bin/make-pot.php` and `bin/compile-translations.php` replace `wp i18n` / `msgfmt`, so no tools are
needed; the release workflow checks that both outputs are up to date. Slack alerts and WP-CLI output
stay in English on purpose: one channel serves sites in every language, and WP-CLI is English.

## Alerts (since v0.0.8)

There is no fallback mailer, so a broken site has to be noticed another way: a **Slack incoming
webhook**, meant for the #status channel. Configured per site, optional.

- **When:** immediately when a claimed email can't be sent; when the twice-daily health check fails
  (only while email types are on: can the key be decrypted, does AhaSend accept it from this
  server via `/v2/ping`), which catches a rotated salt, revoked key or changed server IP before a
  user does; and once with "working again" after a failure (any successful send or check).
- **Throttled:** the same error from the same site is posted at most once per hour.
- **Content:** site, email type, error, masked recipient (`j***@example.com`), a link to the log.
  Never message content.
- **The webhook URL is stored unencrypted, on purpose.** When the salts change, the API key can no
  longer be decrypted and every claimed email fails; an encrypted webhook would become unreadable
  at that same moment and the alert would never arrive. A leaked webhook can only post messages.
- Hooks for other listeners: `xolution_maildelivery/send_failed` ($marker, $recipient, SendResult)
  and `xolution_maildelivery/send_succeeded` ($marker).
- Slack is posted to synchronously (3 s timeout) in the failing request; failures are rare and
  throttled.

## Setup and administration

- **Settings page** (managers only), Settings → MailDelivery, with tabs (since v0.0.11):
  - **Settings:** account ID, API key, sending domain, the computed From address (read-only),
    sandbox mode, and the email types as an accordion per integration (one section open at a time,
    whole rows clickable, a "Select all" per section, live "x of y on" count); "Test connection" (`GET /v2/ping`) and "Send test email".
  - **Alerts:** the Slack webhook and "Send test alert to Slack".
  - **Managers:** who sees the plugin.
  - **Log:** the 7-day log.
  Each tab has its own form and saves only its own section, since an unchecked checkbox isn't
  posted at all: one shared form would let a save on one tab clear another tab's switches. The check
  buttons ask for confirmation in a dialog that says what will happen first.
- **WP-CLI (since v0.0.9)**, `wp maildelivery`, for the sysadmin (who has SSH) to set up a whole server
  at once, since all sites on a server share one key. The key is never an argument (shell history,
  process lists): `setup` asks for it without echo, or reads it from STDIN (`--key-from-stdin`) so
  one typed key can be piped into a loop over all sites. Also `status`, `types`, `enable`,
  `disable`, `test`, `health`, `alert-test`, `managers`, `manager-add`, `manager-remove`. Setting
  up through WP-CLI has no current user, so `--manager` sets the managers. The key is never passed
  through MainWP.
- **One set of rules.** The settings page and WP-CLI save through the same `Configurator`, so both
  refuse the same invalid input and both refuse switching email types on before the plugin can send.
- **MainWP** installs and updates the plugin. A MainWP dashboard extension for settings is a
  possible later step, not part of v0.0.1.

## From address

- Local part: the host of `home_url()`, lower-cased, a leading `www.` removed. Internationalised
  domains in their ASCII (punycode) form.
- A local part may be at most 64 characters. A longer host is cut short and ends in a short hash,
  so it stays unique and traceable.
- From name: the site title.

## Updates and releases (since v0.0.2)

- Plugin header `Update URI: https://github.com/<owner>/<repo>`; the plugin answers WordPress's
  `update_plugins_github.com` filter. The repository is configured only in that header.
- It reads a static `update.json` from `releases/latest/download/update.json`, **not** the GitHub
  API. The unauthenticated API allows 60 requests per hour per IP, and many sites share a server IP.
  The result is cached for 3 hours (1 hour after a failure); "Check again" on Dashboard → Updates
  skips the cache.
- Only packages under the repository's own `releases/download/` are accepted.
- **The repository must be public**: release files of a private repo can't be downloaded anonymously.
- Auto-updates are switched on for this plugin through the `auto_update_plugin` filter, so sites
  update themselves on WordPress's twice-daily update run. MainWP sees the update the normal way.
  `define('XMD_DISABLE_AUTO_UPDATE', true)` in `wp-config.php` stops forcing it: the site's own
  setting applies.
- The release workflow pins its actions to commit SHAs: it publishes what every site installs, so a
  moved tag upstream must not change it.
- "View details" is answered by the plugin itself (`plugins_api`), not by wordpress.org.
- Non-managers don't see the update on Dashboard → Updates (cloaking).
- `.github/workflows/release.yml`, on every `v*` tag: checks the tag matches the plugin header
  and `VERSION` constant, lints, builds `xolution-maildelivery.zip` and `update.json`, publishes
  the release with generated notes.
- Releasing: bump `Version:` and `VERSION` in `xolution-maildelivery.php`, commit, tag `vX.Y.Z`,
  push with `--follow-tags`.

## Code layout

```
xolution-maildelivery/
  assets/admin.css, admin.js   settings page: email-type accordion, confirm dialogs
  xolution-maildelivery.php   plugin header, autoloader, boots on `init`
  uninstall.php               drops the log table, settings and cron
  src/
    Plugin.php                wiring, configuration check, API client factory
    Api/Client.php            HTTP, one retry, per-recipient result check
    Api/SendResult.php
    Mail/Dispatcher.php       pre_wp_mail, plus stripping stray markers
    Mail/Sender.php           payload, one request per address, log, wp_mail_failed/succeeded
    Mail/Claim.php            the marker header
    Mail/Headers.php, Address.php, Attachments.php, FromAddress.php, HtmlToText.php
    Integration/Integration.php, EmailType.php, Registry.php
    Integration/FilteredEmailType.php, PlannedEmailType.php
    Integration/WpCore/WpCoreIntegration.php, EmailChangeConfirmation.php
    Integration/Shield/ShieldIntegration.php, TwoFactorLoginEmail.php, EmailSendingVerification.php
    Settings/Options.php, Secrets.php, Managers.php, Configurator.php
    Cli/Command.php           wp maildelivery …
    Log/LogTable.php
    Admin/Cloak.php, SettingsPage.php, Notices.php, EmailTypesField.php (accordion)
    Alerts/SlackAlerts.php      Slack webhook alerts and the health check
    Update/GitHubUpdater.php  update check, View details, auto-update
.github/workflows/release.yml
```

## Still to verify

- That MainWP sees and updates the plugin while it is cloaked.
- WP-CLI on a real server (built and tested against a fake WP-CLI; the hidden prompt needs a Linux terminal).
- Shield's email sending verification going through AhaSend (v0.0.6). Not testable on the dev
  site: Shield remembers a passed verification, even after switching Email Authentication off and
  on again. Test on the next new site: switch on both Shield types first, then Email Authentication.
- An end-to-end send with a real per-server key (the local test key is not allowed to send from
  the sending domain).

Verified in the sandbox on 2026-11-10: dotted From local parts, and tags containing `/`.

Verified on the dev site on 2026-11-10: connection test, test email, and a real password reset
(delivered in 4 s on the first attempt, tracking off, tag set, From as designed, link not rewritten;
WP 7's reset email has the bare link, no `<...>`).
