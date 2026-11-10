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
- **v0.0.1 targets one website.** Distribution (updater, release workflow, WP-CLI) comes after it
  works there.

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
    /** @return EmailType[] */
    public function emailTypes(): array;
}

interface EmailType {
    public function id(): string;            // 'password-reset' → marker 'wp-core/password-reset'
    public function label(): string;
    public function isImplemented(): bool;   // shown but not switchable while false
    public function contentType(): string;   // 'text/plain' | 'text/html'
    public function register(Claim $claim): void; // hooks the source, calls $claim->mark($atts)
}
```

`register()` only runs for types that are switched on.

### WordPress Core integration

| Email type | Source filter | v0.0.1 |
|---|---|---|
| Password reset | `retrieve_password_notification_email` | implemented |
| New user: set your password (to user) | `wp_new_user_notification_email` | listed, not implemented |
| New user (to admin) | `wp_new_user_notification_email_admin` | listed, not implemented |
| Password changed | `wp_password_change_notification_email` / `password_change_email` | listed, not implemented |
| Email address changed | `email_change_email` | listed, not implemented |
| Admin email change confirmation | `new_admin_email_content` | listed, not implemented |

## Sending

Every request: `POST /v2/accounts/{account_id}/messages`, Bearer key, and

- exactly one recipient (FINDINGS decisions 2 and 4: one request per To address, CC/BCC dropped);
- `tracking: { "open": false, "click": false }`;
- `retention: { "metadata": 7, "data": 1 }`. Found on the first real send (2026-11-10): `data: 0`
  needs a Pro/Max plan **and approval from AhaSend**. Without approval AhaSend does not reject it but
  silently keeps the content for the account default (7 days), reset link included. 1 day needs no
  approval and barely outlives a WordPress reset key (valid 1 day). Switch to 0 once approved
  (`Sender::DATA_RETENTION_DAYS`);
- `tags: ["<integration>/<email-type>"]`;
- an `Idempotency-Key` generated once per address per `wp_mail()` call.

Success means HTTP 202 **and** the single item in `data` has status `queued` or `scheduled`.

**Timeout and retry.** Timeout 10 s. One automatic retry, with the same idempotency key, on a
network timeout, `429` or `5xx`, waiting for `Retry-After` up to 3 s. A user is waiting on
wp-login.php, so it doesn't retry longer than that. No retry on `400`, `401`, `403`, `422`.

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
- To verify: that MainWP still sees the plugin for updates and its plugin list while it is cloaked.

## Local log

- Its own table, `{prefix}xmd_log`: time (UTC), integration, email type, recipient, subject,
  status, HTTP status, AhaSend message ID, error message. No body, no headers, so no login links.
- Entries older than **7 days** are deleted by a daily cron job.
- Shown to managers on a log page, newest first, filterable by status and email type.
- Recipient addresses are personal data; 7 days keeps that minimal.

## Setup and administration

- **Settings page** (managers only): API key, account ID, sending domain, the computed From address
  (read-only), a switch per email type, sandbox mode, managers, "Test connection" (`GET /v2/ping`)
  and "Send test email" (to the current manager).
- **WP-CLI (later, not in v0.0.1)** for setting up a whole server at once, since all sites on a server share one key:
  `wp maildelivery setup` asks for the key on a hidden prompt (so it doesn't end up in shell history),
  plus `status`, `test <email>`, `managers …`; run by the sysadmin, who has SSH. The key is never
  passed through MainWP.
- **MainWP** installs and updates the plugin. A MainWP dashboard extension for settings is a
  possible later step, not part of v0.0.1.

## From address

- Local part: the host of `home_url()`, lower-cased, a leading `www.` removed. Internationalised
  domains in their ASCII (punycode) form.
- A local part may be at most 64 characters. A longer host is cut short and ends in a short hash,
  so it stays unique and traceable.
- From name: the site title.

## Updates and releases (later, not in v0.0.1)

- Plugin header `Update URI: https://github.com/<owner>/<repo>`; the plugin answers WordPress's
  `update_plugins_github.com` filter.
- It reads a static `update.json` from `releases/latest/download/update.json`, **not** the GitHub
  API. The unauthenticated API allows 60 requests per hour per IP, and many sites share a server IP.
- Auto-updates are switched on for this plugin through the `auto_update_plugin` filter.
- A GitHub Action on every `v*` tag builds the plugin zip (folder named after the slug) and
  `update.json`, and attaches both to the release.
- Version numbers: tag `v0.0.1` → plugin version `0.0.1`.

## Code layout

```
xolution-maildelivery/
  xolution-maildelivery.php   plugin header, autoloader, boots on `init`
  uninstall.php               drops the log table, settings and cron
  src/
    Plugin.php                wiring, configuration check, API client factory
    Api/Client.php            HTTP, one retry, per-recipient result check
    Api/SendResult.php
    Mail/Dispatcher.php       pre_wp_mail, plus stripping stray markers
    Mail/Sender.php           payload, one request per address, log, wp_mail_failed/succeeded
    Mail/Claim.php            the marker header
    Mail/Headers.php, Address.php, Attachments.php, FromAddress.php
    Integration/Integration.php, EmailType.php, PlannedEmailType.php, Registry.php
    Integration/WpCore/WpCoreIntegration.php, PasswordReset.php
    Settings/Options.php, Secrets.php, Managers.php
    Log/LogTable.php
    Admin/Cloak.php, SettingsPage.php, Notices.php
```

## Still to verify

- That MainWP sees and updates the plugin while it is cloaked.
- An end-to-end send with a real per-server key (the local test key is not allowed to send from
  the sending domain).

Verified in the sandbox on 2026-11-10: dotted From local parts, and tags containing `/`.

Verified on the dev site on 2026-11-10: connection test, test email, and a real password reset
(delivered in 4 s on the first attempt, tracking off, tag set, From as designed, link not rewritten;
WP 7's reset email has the bare link, no `<...>`).
