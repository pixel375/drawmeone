# Draw Me One v1.9 — SMTP mail readiness and test workflow

Implemented November 23, 2026. **Not yet activated**: the code can safely
detect configured outgoing mail, but there is no verified mailbox credential
stored in the repository and customer accounts remain disabled.

## Why SMTP

PHP's native `mail()` can report a message accepted by the local mail program
without proving receipt. This phase introduces explicit authenticated TLS SMTP
through PHP cURL, which Hostinger documents as `smtp.hostinger.com:465`
(implicit TLS) or port `587` with STARTTLS. SMTP acceptance still does not
guarantee inbox delivery.

## New features

- `app/mailer.php` performs TLS SMTP with strict certificate verification,
  authentication, recipient/from/subject validation and no HTML or attachments.
- `app/auth.php` now uses the dedicated mailer for signup verification and
  password reset. Mail readiness requires **explicitly selected and configured**
  transport; disabled/unconfigured mail fails closed.
- `api/admin/email.php` supports a safe admin-only GET readiness check and
  a `POST action=send_test`: exact-origin HTTPS, CSRF, verified admin session,
  at most **two tests per hour**, and always sent **only to the registered
  administrator email address**. It cannot be turned into an open mail relay.
- Artist's Desk → Website settings → Transactional email diagnostics lets the
  artist inspect readiness and request one controlled mail test.
- No SMTP username/password is included in the public GitHub repo, browser
  API responses or audit logs.

## Set up the domain mailbox (owner action)

Create a real mailbox at `@drawmeone.com` using Hostinger Email or the
owner's chosen mail provider. For Hostinger Mail, consult hPanel's exact
mailbox settings and verify MX, SPF and DKIM. Use its full mailbox address as
both SMTP username and from address. Keep its password private.

If you use a different provider, use its documented SMTP host/port (465 or
587), domain-authenticated mailbox, and DNS settings.

## Configure without SSH manually

GitHub repo → Settings → Environments → existing
**`hostinger-diagnostics`** → add:

Environment **variables**:
- `DMO_SMTP_USER`: your owned verified `@drawmeone.com` mailbox address.
- `DMO_SMTP_HOST`: optional; default `smtp.hostinger.com`.
- `DMO_SMTP_PORT`: optional; default `465` (or `587` for STARTTLS).

Environment **secret**:
- `DMO_SMTP_PASSWORD`: that mailbox's SMTP password or app-specific password.

This uses the SSH key and pinned host key already configured in the same
environment. **Never put mail passwords in chat, workflow files, Git commits,
Actions artifacts or variable fields.**

Once those values are set, ChatGPT can trigger the fixed workflow
`.github/workflows/hostinger-mail-config.yml` by committing the marker
`.github/ops/mail-config.trigger` to main. The workflow only calls the
preinstalled `app/cli-mail-config.php configure` through verified SSH and
passes validated configuration via encrypted stdin. On Hostinger, it updates
only SMTP/mail-related fields in the existing private `.env` atomically,
stores the SMTP password as **base64-encoded text** (encoding is NOT
encryption), and restricts file permissions to `0600`.

The installer **refuses to run if customer login, registration, or commission
submissions are currently open**. The action changes
`DMO_MAIL_ENABLED=1`, but does NOT change
`DMO_AUTH_ENABLED`, `DMO_REGISTRATION_ENABLED`,
`DMO_REQUESTS_ENABLED` or legal-policy approval flags.

## Live test

After setup, open `https://drawmeone.com/studio-admin/index.php`, sign in,
go to Website settings, find **Transactional email diagnostics**, and click
`Send a test email to my admin address`. Observe the reference shown and
verify the message reaches the administrator's actual inbox, not only that
the SMTP transport accepted it. Check spam and mail DNS authentication.

Do not enable public customer accounts based on SMTP acceptance alone.
Before public signup, run a controlled end-to-end signup, email verification,
recovery, invalid/expired token, duplicate email and role-isolation QA.

## Optional explicit PHP mail() compatibility

`DMO_MAIL_TRANSPORT=php` is supported **only if**
`DMO_ALLOW_PHP_MAIL=1`; it is not recommended for launch. There is no silent
fallback to PHP mail if SMTP is misconfigured.

## Next milestones

1. Confirm actual delivered mail via admin test.
2. Review policies in `docs/CUSTOMER_POLICIES_DRAFT.md` and publish final
   pages with valid owner contact and legal information.
3. Build a narrow, test-only activation workflow for customer accounts,
   then verify signup/verification/password-reset before general opening.
4. Keep `DMO_REQUESTS_ENABLED=0` until all safeguards pass.

## Automatic safe regression smoke

The read-only Hostinger SSH diagnostic now lints the new PHP source and runs `tests/mail_smoke.php`. This verifies MIME/CRLF header construction and UTF-8 content handling without connecting to SMTP, sending messages, reading passwords, or modifying MySQL. Result should be `MAIL_FORMAT_SMOKE=PASS`. A successful smoke test **does not verify live deliverability**; use the administrator test after protected SMTP configuration.

## Diagnostic: missing test email (2026-11-23)

Previously, `transactional_mail_ready=yes` only checked that configuration
fields were present; it never connected to the SMTP server. The admin owner
reported no message arriving in Inbox or Spam. We now record the final
**numeric SMTP server response code** and libcurl error number when sending.
We do not log the SMTP transcript, passwords, message body or recipient.

A single, rate-limited CLI test can run via the fixed GitHub Actions workflow
`.github/workflows/hostinger-smtp-probe.yml` when
`.github/ops/smtp-probe.trigger` changes on `main`. This sends a message
only to the **existing verified administrator email stored in MySQL**; never
to an address from a workflow input or arbitrary user command. The server
enforces at most one CLI test per 60 minutes. An Actions result of
`smtp_transport_accepted=yes` means the sending server accepted the
message, **not** that Gmail or another receiving provider delivered it.

Interpreting safe results:
- Nonzero `smtp_curl_code`: TLS/connection/authentication/SMTP protocol
  failure, requiring investigation. No credentials in logs.
- SMTP `4xx` or `5xx`: server-side refusal; review Hostinger mailbox
  limits/authorization, support logs, provider configuration.
- SMTP `250` or `251` with cURL 0: server accepted the message. Check
  Hostinger Email delivery logs, domain SPF/DKIM/DMARC, bounces and Gmail
  filtering. Never claim delivery solely from a successful send.
- Missing reference in Inbox and Spam despite SMTP acceptance: check
  provider message tracking and DNS. Hostinger documents its outgoing
  host as `smtp.hostinger.com` with SSL port 465 or STARTTLS port 587.
