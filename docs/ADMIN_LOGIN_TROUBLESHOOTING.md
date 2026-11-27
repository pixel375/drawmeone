# Draw Me One — Admin login troubleshooting

Updated 2026-11-23. The administrator login stays protected with password, Cloudflare Turnstile, server-side rate limits and strict session checks. No MFA is required.

## What changed

The Artist's Desk now displays distinct **safe** login failure categories instead of one vague "sign-in not accepted" message:

| HTTP response | Meaning | Next action |
| --- | --- | --- |
| 401 | Email/password or active administrator status not verified | Run the read-only CLI account check |
| 422 with verification_failed | Cloudflare Turnstile rejected a challenge | Check widget hostname/action and secret; inspect PHP error log |
| 403 | HTTPS or same-origin verification failed | Check Hostinger reverse-proxy TLS variables; don't turn off security |
| 429 | Rate limiter blocked repeated attempts | Wait at least 15 minutes; avoid repeated testing |
| 503 | Server-side login failure | Read Hostinger's PHP error log (never post private values) |
| 200 then session missing | Cookie or session not recognized | Check browser cookies and secure HTTPS context |

The API still deliberately uses one generic `invalid_credentials` result for any nonexistent, unverified or incorrect login, so it doesn't reveal account existence.

## Confirm password on SSH without resetting or displaying it

From your actual website root `~/domains/drawmeone.com/public_html`:

```bash
cd ~/domains/drawmeone.com/public_html
read -srp "Admin password to verify: " DMO_ADMIN_PASSWORD
echo
export DMO_ADMIN_PASSWORD
php app/cli-admin.php check YOUR_ADMIN_EMAIL@example.com
unset DMO_ADMIN_PASSWORD
```

Replace the email with the **exact email used for CLI administrator creation**.

Possible outcomes:

- `Admin credentials and account status: OK` — the stored password matches an active, verified administrator. Browser login is then likely failing earlier in Turnstile/HTTP validation or during session creation.
- `Admin credentials or account status: NOT VERIFIED` — email mismatch, wrong password, or inactive/unverified role. Check the exact email in the original create command; don't paste the password into ChatGPT.
- `Unable to finish` — check the private PHP error log/database connectivity.

This `check` action performs **no database writes** and prints neither passwords nor hashes. You can repeat it after a password reset.

If you need to reset (existing CLI feature):

```bash
read -srp "New admin password (16+ characters): " DMO_ADMIN_PASSWORD
echo
export DMO_ADMIN_PASSWORD
php app/cli-admin.php reset YOUR_ADMIN_EMAIL@example.com
unset DMO_ADMIN_PASSWORD
```

Resetting deletes existing administrator sessions.

## Diagnose Turnstile without weakening security

If browser login reports a rejected CAPTCHA (422), look in Hostinger PHP error logs for `[DrawMeOne] Turnstile:`. The backend now logs **only** safe reason codes:

- `validation_failed_invalid-input-secret`: likely wrong/incorrect widget's secret in the private .env.
- `validation_failed_timeout-or-duplicate`: token expired or reused; refresh and try again once.
- `hostname_mismatch`: widget token wasn't issued for `drawmeone.com`.
- `action_mismatch`: challenge returned a different action than `admin_login`.
- `transport_or_http_error_0`: server couldn't contact Cloudflare, possibly PHP cURL or outbound network problem.

Do not copy the PHP error log wholesale, any `.env` contents, session cookies or real Turnstile secrets. The diagnostic code/status alone is enough.

## Remote server access through GitHub Actions

ChatGPT can manage the repository through the connected GitHub integration. A dedicated GitHub Actions job could SSH into Hostinger **after the repository owner securely configures a private deploy key in GitHub Actions Secrets and installs its public key in Hostinger's authorized_keys**. A public key by itself cannot authenticate a remote SSH session.

This conversation currently has repository tooling but no direct interactive SSH shell or connected Hostinger remote-exec tool. Do not paste any SSH private key into chat or commit it. Prefer a separate, narrowly scoped deploy key and manually triggered, read-only diagnostic workflow. Set the host key from an independently verified fingerprint.

## V2 signed-out startup (November 27)

If the login page never becomes usable, the updated panel distinguishes DMO-A01 (availability API), DMO-A02 (session API) and security verification loading failures. Use Retry sign-in once; do not reset credentials for a startup failure. The production browser smoke loads the actual public page without credentials and checks the widget mounts; it never submits a login. Hostinger checks status and anonymous session JSON and publicly served asset hashes. A mounted widget does not prove the owner's challenge or password succeeds. No email test is required to sign in.

## Current V2 policy — November 27

The live signed-out CAPTCHA failed to mount in a real browser and blocked the owner. Private Artist Desk login now defaults to password-only with existing database-backed IP/account limits, verified active admin role, HTTPS/origin checks and secure sessions. CAPTCHA is optional via private server `DMO_ADMIN_REQUIRE_CAPTCHA=1`; it cannot be bypassed by a client when required. Public guest enquiries still require CAPTCHA. This removes a bot-screening layer on the admin route; use a unique long password. Earlier CAPTCHA-required instructions above are historical or apply when explicitly re-enabled. No SMTP test is needed to log in.
