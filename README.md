# Xolution MailDelivery

A WordPress plugin that takes over selected WordPress emails and sends them through the
[AhaSend](https://ahasend.com) API instead of the web server's own mailer.

Built by [Xolution](https://xolution.nl) for the WordPress sites it manages, where login emails have
to arrive: with Shield Security's email 2FA and auto-login links, an email that doesn't arrive means
a user who can't log in. It is public so you can use it too. Questions: contact@xolution.nl.

## How it works

- **Selective.** Only email types you switch on go through AhaSend. Everything else WordPress sends
  the normal way, untouched. Each type has its own switch, and all are off after installing.
- **Integrations.** Each source of email (WordPress Core, Shield Security, ...) is an integration that
  recognises its own emails and claims them. One dispatcher sends everything claimed.
- **No fallback.** A claimed email that AhaSend doesn't accept fails: it is logged, WordPress's
  `wp_mail_failed` fires, and an optional Slack alert goes out. It is never handed to the server's
  mailer instead, because that is the unreliable route this plugin replaces.
- **One recipient per request.** An email to several addresses becomes one request per address;
  CC and BCC are dropped.

### Supported emails

| Integration | Email type | Marker |
|---|---|---|
| WordPress Core | Password reset | `wp-core/password-reset` |
| | New user: login details (to the user) | `wp-core/new-user` |
| | New user registration (to the site admin) | `wp-core/new-user-admin` |
| | Password changed (to the user) | `wp-core/password-changed` |
| | Password reset by a user (to the site admin) | `wp-core/password-changed-admin` |
| | Email address changed (to the old address) | `wp-core/email-changed` |
| | Email change confirmation (to the new address) | `wp-core/email-change-confirm` |
| | Admin email change confirmation (to the new address) | `wp-core/admin-email-change` |
| Shield Security | Email 2FA login (code and auto-login link) | `shield/two-factor-login` |
| | Email sending verification | `shield/email-verification` |
| | Instant alert: Shield deactivated | `shield/alert-shield-deactivated` |
| | Instant alert: cloaked plugins | `shield/alert-cloaked-plugins` |
| | Instant alert: admin accounts (ShieldPRO) | `shield/alert-admin-accounts` |
| | Instant alert: admin login | `shield/alert-admin-login` |
| | Instant alert: firewall blocks | `shield/alert-firewall-block` |
| | Instant alert: vulnerabilities (ShieldPRO) | `shield/alert-vulnerabilities` |
| | Instant alert: FileLocker changes (ShieldPRO) | `shield/alert-filelocker` |
| | Alert report ("Security Alert") | `shield/report-alert` |
| | Info report ("Security Report") | `shield/report-info` |
| | Login notice (to the user) | `shield/login-notice` |
| | Backup login code used (to the user) | `shield/backup-code-used` |
| | IP unblock link (to the user) | `shield/ip-unblock` |
| | Security Admin removal confirmation | `shield/security-admin-removal-confirm` |
| | Security Admin removed | `shield/security-admin-removed` |

Shield's alerts, reports and Security Admin emails go to Shield's **Report Email** (Reports →
Reporting & Alerts Configuration), or to the site admin email when that is empty. Switching a type
on here doesn't switch the email on in Shield: it only changes the route of emails Shield already
sends.

**Gravity Forms and WooCommerce** are in the private Pro add-on (Gravity Forms since v0.0.22; it was
built in before). On a site without Pro their emails go through the server's mailer. Switches saved
before v0.0.22 stay stored and work again once Pro is installed, unless the Settings tab is saved
without Pro in the meantime, which drops them.

The marker is also the message's tag in AhaSend, so you can filter by email type in the dashboard.

### What every email looks like to AhaSend

- **From:** `<site-host>@<sending-domain>`, e.g. `client-site.nl@mail-delivery.example.com`, with the
  site title as name. Every site is recognisable in AhaSend's statistics.
- **Tracking off.** Links are never rewritten, so login links go straight to the site.
- **Content and metadata kept 7 days** at AhaSend, so support can read the exact email a user
  received. Login and reset links in those emails are single-use or expire within a day, but anyone
  with access to the AhaSend account can read them while they're valid: keep that access tight.

## Requirements

- WordPress 7.0 or newer, PHP 8.4 or newer (with the sodium extension, standard since PHP 7.2).
- An AhaSend account with a verified sending domain.
- Salts defined in `wp-config.php` (WordPress's standard install does this).

## Set it up yourself

### 1. AhaSend

1. Add and verify a **sending domain** in AhaSend. A subdomain such as `mail-delivery.example.com`
   keeps it apart from your main domain's mail.
2. Note your **account ID** (dashboard, account settings).
3. Create an **API key (v2)** under Credentials with only the scope
   `messages:send:<your sending domain>`, and lock it to your web server's outgoing IP address with
   the key's **IP allow list**. A stolen key can then only send mail, and only from that server.
   One key per server is a good balance: every site on that server can share it.

### 2. Install

1. Download `xolution-maildelivery.zip` from the
   [latest release](https://github.com/jasperfrontend/ahasend-v2-wordpress/releases/latest).
2. In WordPress: Plugins → Add New → Upload Plugin, then activate it. **The user who activates it
   becomes the plugin's first manager** (see Managers below).

After that the plugin updates itself from this repository's releases, like any other plugin. Your
own management tool (MainWP, ManageWP, ...) sees the updates the normal way.

Auto-updates are switched on for this plugin regardless of the site's setting. To roll updates out
yourself instead, add `define('XMD_DISABLE_AUTO_UPDATE', true);` to `wp-config.php`: the site's own
auto-update setting then applies.

### 3. Configure

**Quickest: the Setup tab** (Settings → MailDelivery → Setup, which opens by itself on a site that isn't
set up yet):

1. Drop an `.xmd` config file on it, or fill in the account ID, sending domain and server IPs by hand.
   The emails it lists are ticked in the checklist below; emails of plugins that aren't active on
   this site are skipped and reported.
2. Fill in a **setup key**: an AhaSend API key with only the scopes `api-keys:write` and
   `domains:read`. It's used once and never saved. Lock it to your servers' IPs with its IP allow
   list: anyone holding it can create keys.
3. Click **Set up MailDelivery**. The plugin checks the sending domain, creates this site's own key
   (it may only send from the sending domain, and only from the server IPs), tests it, and only then
   saves everything, switches the emails on and sends you a test email. A dialog shows every step.

**Download config file**, on the same tab, saves a site's setup as an `.xmd` file for the next site:
account ID, sending domain, server IPs, Slack webhook, sandbox mode and the switched-on emails. Never
an API key. Re-running the setup on a site creates a new key; the old one stays in AhaSend until you
delete it there, or until you uninstall.

**Uninstall MailDelivery**, at the bottom of the same tab, removes everything: first this site's key
in AhaSend, then all settings and the log, then the plugin itself. It takes a setup key with the
scope `api-keys:delete`, plus `api-keys:read` to also find keys from earlier setups of this site
(required for sites set up with v0.0.19, which didn't save the key's ID). If deleting the key fails,
nothing on the site is removed. Without a setup key the key stays in AhaSend and the rest goes.
Deleting the plugin on the Plugins screen removes the site's data too, but never the key in AhaSend.

**By hand**, on the Settings tab:

1. Fill in the account ID, the API key and the sending domain, then save. The page shows the From
   address it will use.
2. Click **Test connection** (does AhaSend accept the key from this server?) and **Send test email**
   (to your own address).
3. Switch on the email types you want, and save.

**Sandbox mode** lets AhaSend accept emails without delivering them. Only for testing: while it's
on, password reset emails and the like never arrive.

### 4. Shield Security's email 2FA

This is how Shield's "magic link" login is set up: Email Authentication enforced for the user
roles you choose, plus Auto-Login Links. Do it in this order:

1. In MailDelivery, switch on **both** Shield types and save.
2. In Shield, enable Email Authentication. Shield first sends its email sending verification, which
   now goes through AhaSend, so it tests the same route the 2FA emails will take.
3. Enable Auto-Login Links and choose the roles under "Enforce - Email Authentication".

Test with a user whose inbox only you can read, and keep an administrator logged in elsewhere
while you do: there is no fallback, so a misconfiguration means that user can't log in.

### 5. Alerts

On the **Alerts** tab, paste a Slack incoming webhook URL and click **Send test alert to Slack**. The
plugin then posts:

- when a claimed email can't be sent;
- when the twice-daily health check fails: can the key still be decrypted, and does AhaSend still
  accept it from this server? This catches a revoked key or a changed server IP before a user does;
- once more when things work again.

The same error is posted at most once per hour per site. Posts contain the site, the email type,
the error and a masked recipient (`j***@example.com`), never message content.

### 6. Managers

Clients often have administrator accounts, so the plugin is hidden from everyone except its
**managers**: they alone see it in the Plugins list, its settings, its log and its notices. Pick the
managers on the **Managers** tab. If no listed manager exists any more, every
administrator counts as one again, so access can always be recovered. This keeps the plugin out of
sight; it is not a security boundary, as an administrator can always run code.

## WP-CLI

Everything above can be done with `wp maildelivery`, for example by a sysadmin setting up every site
on a server at once. The API key is never passed as an argument (it would end up in shell history and
process lists): it is asked for without echo, or read from STDIN.

```sh
# One site: asks for the key without showing it.
wp maildelivery setup --account-id=<uuid> --sending-domain=mail-delivery.example.com \
  --manager=admin --enable=wp-core/password-reset,shield/two-factor-login,shield/email-verification

# Every site on a server, with the key typed once.
read -rs KEY
for site in /var/www/*; do
  printf '%s' "$KEY" | wp --path="$site/public" maildelivery setup --key-from-stdin \
    --account-id=<uuid> --sending-domain=mail-delivery.example.com --manager=admin
done
unset KEY
```

| Command | What it does |
|---|---|
| `wp maildelivery setup` | Saves credentials, and optionally `--enable`, `--manager`, `--slack-webhook`, `--[no-]sandbox`. Use `--keep-key` to change other settings only. Checks the connection. |
| `wp maildelivery status` | Shows the settings and whether AhaSend accepts the key (`--no-ping` to skip that). |
| `wp maildelivery types` | Lists every email type with its marker and whether it's on. |
| `wp maildelivery enable <marker>...` / `disable <marker>...` / `disable --all` | Switches email types on or off. |
| `wp maildelivery test <email>` | Sends a test email. |
| `wp maildelivery health` | Runs the health check now. |
| `wp maildelivery alert-test` | Posts a test message to Slack. |
| `wp maildelivery managers` / `manager-add <user>...` / `manager-remove <user>...` | Lists or changes the managers (logins, emails or IDs; at least one must stay). |

Run `wp help maildelivery <command>` for all options.

## Log

**Settings → MailDelivery → Log** lists every email the plugin handled in the last 7 days: time,
email type, recipient, subject, outcome and AhaSend's message ID or the error. Never the content.
Older entries are deleted daily.

## Security notes

- The API key is stored **encrypted** (libsodium) with a key derived from the salts in
  `wp-config.php`, so a database dump or backup alone doesn't reveal it. Anyone who can read
  `wp-config.php` too, or run PHP on the site, can still decrypt it: the site has to, to send.
- If the salts change (some security plugins and hosts rotate them), the key can no longer be
  decrypted. Claimed emails fail, managers get a notice and Slack an alert: re-enter the key.
- The Slack webhook is stored **unencrypted on purpose**, so the alert still arrives in exactly that
  situation. A leaked webhook can only post messages to the channel.

## Translations

All text in WordPress is translatable, with the text domain `xolution-maildelivery`. **Dutch
(`nl_NL`) is included**; sites in other languages see English.

- The template is `xolution-maildelivery/languages/xolution-maildelivery.pot`.
- A translation is `xolution-maildelivery-<locale>.po` next to it (edit it with Poedit or any text
  editor), compiled into the `.mo` and `.l10n.php` files WordPress loads by
  `php bin/compile-translations.php`. It also reports strings that aren't translated yet; those
  show in English.
- After adding or changing strings in the code, run `php bin/make-pot.php`, update the `.po`
  files, compile, and commit all of it. The release workflow refuses to release when the template
  or the compiled files are out of date.

Slack alerts and WP-CLI output stay in English: one Slack channel serves every site whatever its
language, and WP-CLI itself is English.

## Releasing (maintainers)

Bump `Version:` and `VERSION` in `xolution-maildelivery/xolution-maildelivery.php`, commit, then:

```sh
git tag -a vX.Y.Z -m "Xolution MailDelivery X.Y.Z"
git push origin main --follow-tags
```

The release workflow checks the tag against the plugin version, lints, builds the zip and
`update.json`, and publishes the release. Sites pick it up on their next update check (Dashboard →
Updates → "Check again" to speed that up).

## Adding an integration

An integration implements `Integration` (in `src/Integration/`) and lists its `EmailType`s. An email
type hooks into its source in `register()` and marks the email with `Claim::mark()`. When the source
hands its `wp_mail()` arguments to a filter, `FilteredEmailType` does it all. When it doesn't, match
the next `wp_mail()` call on something only that email contains, as `Shield\TwoFactorLoginEmail` and
`WpCore\EmailChangeConfirmation` do. A separate plugin can add integrations too, with the filter
`xolution_maildelivery_integrations` (see "Add-ons" in ARCHITECTURE.md). See [ARCHITECTURE.md](ARCHITECTURE.md) for the design and every
decision behind it, and [FINDINGS.md](FINDINGS.md) for the comparison with AhaSend's original plugin.

## License

GPL-2.0-or-later.
