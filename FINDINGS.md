# AhaSend WordPress plugin vs. the current AhaSend API

A comparison of the official AhaSend WordPress plugin against AhaSend's current documentation,
written as the starting point for a v2 version of the plugin.

- Plugin: https://github.com/AhaSend/wordpress-plugin, `ahasend.php`, version 1.3 (commit
  `b2924a2`, 2025-05-30). Line numbers below refer to that file at that commit.
- Docs: https://ahasend.com/docs, read on 2026-11-10.
- Not tested against the live API (no AhaSend key was used). Everything below is code read against
  docs.

## Verdict

The plugin still works, but only because AhaSend keeps API v1 alive. It is written entirely against
v1, which the docs now call legacy.

- v1 status: **"Maintenance mode"**, and the docs say it "will continue to work". No sunset or
  end-of-life date is published anywhere in the docs.
- v2 status: **"Actively developed"**, OpenAPI version `2.0.0`. The v1 reference says to use v2 for
  new integrations.

## What the plugin does today

The main plugin writes a must-use plugin to `wp-content/mu-plugins/ahasend-mu-mailer.php`
(lines 21-100). That file replaces the pluggable `wp_mail()` and sends one request:

```
POST https://api.ahasend.com/v1/email/send
X-Api-Key: <api key>
Content-Type: application/json

{
  "from": { "email": "<from_email option>", "name": "<from_name option>" },
  "recipients": [ { "email": "<to>", "name": "" } ],
  "content": {
    "subject": "<subject>",
    "html_body": "<message, or nl2br(esc_html(message)) when not HTML>"
  }
}
```

It counts any 2xx response as success (line 92). Settings are three options: `ahasend_api_key`,
`ahasend_from_email`, `ahasend_from_name`.

This matches the documented v1 shape exactly: endpoint, `X-Api-Key` auth, and `content.subject` /
`content.html_body`.

There is also a commented-out older approach (lines 114-182) that hooked the `wp_mail` filter. It is
dead code.

## What moving to v2 involves

| | v1 (plugin today) | v2 (current) |
|---|---|---|
| Endpoint | `POST /v1/email/send` | `POST /v2/accounts/{account_id}/messages` |
| Auth | `X-Api-Key: <key>` | `Authorization: Bearer aha-sk-<64 chars>` |
| Account ID | not needed | required UUID path parameter, from dashboard account settings |
| Subject | `content.subject` | `subject` (top level) |
| HTML body | `content.html_body` | `html_content` |
| Text body | `content.text_body` | `text_content` |
| Reply-To | `content.reply_to` | `reply_to` (top level, Address object) |
| Headers | `content.headers` | `headers` (top level) |
| Attachments | `content.attachments` | `attachments` (top level) |
| Success | `201` with `success_count`, `fail_count`, `failed_recipients`, `errors` | `202` with `{"object":"list","data":[...]}`, one item per recipient |
| Error body | `{"status": "..."}` | `{"message": "..."}` |

Consequences for the plugin:

- **New setting: account ID.** The plugin has nowhere to store one today.
- **Possibly a new key.** v2 keys have the `aha-sk-` format and carry scopes. The key needs
  `messages:send:all` or `messages:send:{domain}` for the From domain. Whether a key created for v1
  works on v2 is not stated in the docs: unverified.
- **Success is per recipient.** v2 can answer 202 while one recipient has `"status": "error"`
  (with `id` and `message_id_header` null and the reason in `error`). The plugin must check every
  item in `data`, not just the HTTP code. Item statuses are `queued`, `scheduled` or `error`.
- **Key test endpoint:** `GET https://api.ahasend.com/v2/ping` with the same Bearer header. Useful
  for a "Test connection" button on the settings page.

### v2 request body, for reference

Required: `from`, `recipients`, `subject`, and one of `text_content` / `html_content`.

- `from`: `{ "email" (required), "name" }`. Must be on a domain verified in AhaSend.
- `recipients`: 1-100 items, each `{ "email" (required), "name", "substitutions" }`. Each recipient
  gets a **separate message**; it does not build one message with several To addresses.
- `subject`, `text_content`, `html_content`, `amp_content`.
- `reply_to`: Address object. Do not also put a Reply-To in `headers`.
- `attachments`: each `{ "data", "content_type", "file_name" }` required, plus optional `base64`
  (bool, default false), `content_disposition`, `content_id` (wrapped in angle brackets, e.g.
  `<image1@example.com>`, referenced in HTML as `cid:image1@example.com`).
- `headers`: object of string values. Names are visible ASCII, no spaces, no colons. `message-id`
  is ignored and generated.
- `substitutions` (global, recipient-level overrides), `tags` (string array).
- `sandbox` (bool), `sandbox_result` (`deliver`, `bounce`, `defer`, `fail`, `suppress`).
- `tracking`: `{ "open", "click" }`, booleans or null.
- `retention`: `{ "metadata": 1-30, "data": 0-30 }` days.
- `schedule`: `{ "first_attempt", "expires" }`, RFC 3339. First attempt within 7 days; expires
  within 8 days (the guide and the OpenAPI spec word the 8-day window differently).
- Optional `Idempotency-Key` request header, max 255 chars, keys expire after 24 hours.

**CC and BCC are not supported on this endpoint.** The docs point to a separate "Create
Conversation Message" endpoint for that. That page was not read, so its path is still to be looked
up.

### Limits

- 100 requests per second per account, burst 200. Over the limit: `429` with `Retry-After`.
- 100 recipients per request.
- Request body 30 MB; resulting message 20 MiB after base64.
- Status codes: `400` bad input, `401` bad credentials, `403` scope / account / IP allow list /
  paused domain, `409` idempotency key in progress, `422` idempotency key reused with a different
  body, `429` rate limit, `500` server error, `503` template check busy (with `Retry-After`).

## Bugs that exist on either API version

These are in the current plugin regardless of v1 or v2, and a rewrite should fix them.

1. **Comma-separated recipients break** (lines 62-64). WordPress accepts `$to` as a string like
   `"a@x.com, b@y.com"`. The plugin sends that whole string as one recipient's email, so the send
   fails. It also ignores `Name <email>` formatting.
2. **Headers are thrown away.** Reply-To, CC, BCC, a per-message From and any custom header in
   `$headers` are dropped. Contact form plugins rely on Reply-To.
3. **Attachments are ignored entirely.** `$attachments` is accepted and never used.
4. **HTML detection is too narrow** (lines 49-59). It only looks for `Content-Type: text/html` in
   `$headers`. Plugins that set HTML through the `wp_mail_content_type` filter get their HTML
   escaped by `esc_html()`, so recipients see raw tags.
5. **No plain-text part.** Plain mail is converted to HTML with `nl2br(esc_html())` and sent only
   as HTML. Nothing is sent as `text_body` / `text_content`.
6. **Core WordPress filters and actions are skipped.** Because it replaces `wp_mail()` wholesale it
   never runs `pre_wp_mail`, the `wp_mail` filter, `wp_mail_from` / `wp_mail_from_name`, or the
   `wp_mail_failed` / `wp_mail_succeeded` actions. Failures are invisible to logging plugins.
7. **Partial failure reports success** (line 92). Any 2xx counts as sent. On v1 a `201` with
   `fail_count > 0` still returns `true`; on v2 the same problem applies to per-recipient errors.
8. **Silent no-op when unconfigured** (lines 45-47). Missing settings return `false` without any
   error or fallback to the default mailer.
9. **No request timeout set.** `wp_remote_post()` defaults to 5 seconds.
10. **The mu-plugin is rewritten on every admin page load** (line 100, `admin_init`), with
    `mkdir()` / `file_put_contents()` and no check that the folder is writable. If the write fails,
    nothing is sent through AhaSend and nothing says so.
11. **Pluggable function conflict.** `if (!function_exists('wp_mail'))` means any other plugin
    that replaces `wp_mail()` first silently wins.
12. **Settings page details.** The API key is shown in a plain `type="text"` field (line 239), and
    the save code reuses the variable name `$ahasend_api_key` for all three fields (lines 210-221).
    Harmless, but confusing.

## Side note: SMTP

The plugin description says "easy SMTP integration", but the code only uses the REST API. For
reference, AhaSend SMTP is `send.ahasend.com`, ports 25 / 587 / 2525 with STARTTLS, port 465 with
implicit TLS, SMTP credentials (not an API key). The docs contradict themselves on 465: the SMTP
page says it is supported, an "Agent Instructions" block elsewhere says it is not.

## Suggested shape for a v2 plugin

- Hook `pre_wp_mail` (WordPress 5.7+) instead of writing a mu-plugin that redefines `wp_mail()`.
  It gets the same arguments, lets core parse headers, and needs no file writes.
- Reuse core's parsing: let `wp_mail` filters run, then split addresses and headers the way core
  does, honour `wp_mail_content_type`, `wp_mail_from`, `wp_mail_from_name`.
- Settings: API key (password field), account ID, From email, From name, optional sandbox toggle,
  and a "Test connection" button calling `/v2/ping`.
- Map Reply-To to `reply_to`, other headers to `headers`, attachments to base64 `attachments`.
  Route mail with CC/BCC to the conversation endpoint once its path is confirmed.
- Treat the send as successful only when every item in `data` is `queued` or `scheduled`, and fire
  `wp_mail_failed` with the API's error otherwise.
- Set an explicit timeout and handle `429` / `503` with `Retry-After`.

## Open questions

- Does a v1 API key work against v2, or does every user need a new `aha-sk-` key?
- Path and body of the Create Conversation Message endpoint (needed for CC/BCC).
- Whether port 465 is actually supported on SMTP (docs disagree).
