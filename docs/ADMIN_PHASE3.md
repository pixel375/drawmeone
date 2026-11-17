# Draw Me One — Artist's Desk (Admin Phase 3)

Updated 2026-11-17. Admin authentication uses **password + server-verified CAPTCHA**, rate limiting, strict admin permissions and secure short-lived sessions. The owner explicitly requested **NO MFA**. There are no authenticator apps, second codes or MFA enrollment requirements.

## What is live

- [Artist's Desk](https://drawmeone.com/studio-admin/index.php) — the real dashboard route; it shows an unavailable screen until admin security is enabled.
- [Admin status](https://drawmeone.com/api/admin/status.php) — safe readiness status; no usernames or secrets.
- API read-only worklist: `/api/admin/overview.php`, `/api/admin/requests.php`, `/api/admin/messages.php`. These require an authenticated admin-only session.
- Separate customer authentication remains disabled, as configured.
- Real data is displayed only to an authorized administrator; no fictional orders, customer counts or messages are generated.

## How admin access is secured — without MFA

- **Strong password:** at least 16 characters for CLI-created admin.
- **Cloudflare Turnstile** challenge on every admin login, verified server-side for hostname `drawmeone.com` and action `admin_login`.
- **Rate limits:** 8 login attempts per 15 minutes by IP, 5 per 15 minutes by email. Limiter keys are keyed-hash values, not raw email/IP addresses.
- **Session:** separate `__Host-dmo_admin` Secure / HttpOnly / SameSite Strict cookie. Database stores only the session hash. Expiration after **12 hours maximum** and **30 minutes without an authenticated request**.
- **Role checks:** only users with role `admin`, active status and email-verified flag may sign in. Customer login explicitly refuses admin accounts.
- **CSRF validation** for sign-out (and future settings-changing actions), strict HTTPS/origin checks, no caching, no unsafe cross-origin reads.
- **Audit log** for admin login, logout, first admin setup and CLI password resets. Stored admin data is never returned to unauthenticated requests.
- **Emergency access:** admin password reset through private SSH/PHP CLI only, not a public unauthenticated reset form.

This arrangement avoids MFA while providing several protections against password guessing and session theft. A stolen password plus a successfully completed challenge can still grant access; no-MFA is a deliberate trade-off.

## Default mode (keep until tested)

The backend ships with admin login **disabled** unless all of these are true:

1. `DMO_ADMIN_ENABLED=1` in the *private* Hostinger `.env`;
2. `DMO_APP_KEY` is a stable cryptographically random secret, at least 32 characters;
3. `DMO_TURNSTILE_SITE_KEY` and `DMO_TURNSTILE_SECRET` are configured for `drawmeone.com`;
4. the existing `002_auth_rate_limits` SQL migration is imported;
5. `pdo_mysql` and PHP cURL are available.

You already imported migration 002. No new database or SQL import is required for this phase.

```dotenv
# Example only, added to /home/<account>/private/.env — NOT GitHub:
DMO_ADMIN_ENABLED=0
DMO_APP_KEY=YOUR_PRIVATE_RANDOM_64_HEX_CHARACTERS
DMO_TURNSTILE_SITE_KEY=YOUR_PUBLIC_CLOUDFLARE_SITE_KEY
DMO_TURNSTILE_SECRET=YOUR_PRIVATE_CLOUDFLARE_SECRET
```

Leave `DMO_ADMIN_ENABLED=0` until controlled verification. Do not post those values in chat or commit them. The private `.env` must remain **outside** `public_html`.

You may generate the app key using an SSH PHP command on your own hosting account:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Save the result privately in `.env`.

## CLI-only first admin setup (when ready)

**Requires SSH / shell access to the Hostinger website files.** Navigate to the deployed `public_html` folder that contains `app/cli-admin.php`. Do not place credentials in a public PHP file, phpMyAdmin query history or command-line arguments.

```bash
# Run in bash on your own server, from the website public_html directory:
read -srp 'New admin password (16+ chars): ' DMO_ADMIN_PASSWORD
echo
export DMO_ADMIN_PASSWORD

php app/cli-admin.php create artist@example.com "Artist"

unset DMO_ADMIN_PASSWORD
```

Replace `artist@example.com` with the artist's intended admin email and `Artist` with her chosen display name. The password is supplied via a temporary environment variable, not command-line arguments or GitHub. The script refuses to create a second administrator if one exists.

If the admin password needs recovery and the user still controls hosting/SSH:

```bash
read -srp 'New admin password: ' DMO_ADMIN_PASSWORD
echo
export DMO_ADMIN_PASSWORD
php app/cli-admin.php reset artist@example.com
unset DMO_ADMIN_PASSWORD
```

A reset immediately revokes all existing sessions for that account. The script is **CLI only**; the entire `app/` directory is blocked from HTTP by .htaccess.

## Safe activation / QA

1. Confirm the public admin status says `admin_schema_ready:true`, `admin_available:false`.
2. Generate app key and set Turnstile keys privately; leave `DMO_ADMIN_ENABLED=0`.
3. Bootstrap exactly one admin using the CLI command after reviewing the code and shell environment.
4. Change `DMO_ADMIN_ENABLED=1` during a controlled test, perform a real Turnstile-protected sign-in, verify that only admin data appears and customer accounts cannot log in.
5. Test an incorrect password, rate limit, sign out, idle expiry, 12-hour max, CSRF rejection, direct unauthenticated access to each data route, and HTTPS/security headers.
6. If anything fails, set `DMO_ADMIN_ENABLED=0` again; database records remain unchanged.

**Never** share private passwords, admin session cookies, Turnstile secrets or `.env` contents in chat. The admin route should remain `noindex,nofollow`, but its security depends on the server-side role check, not search-engine hiding.

## Next phase

Read-only worklists are the beginning. Creating a quote, replying to a client, verifying payment, publishing artwork, or editing the website is **not** activated by this deployment. Those actions require new owner-checked POST endpoints, CSRF protection, audit trails and appropriate business policies.

## Phase 4 design settings

A separate `DMO_DESIGN_WRITES_ENABLED=0` switch gates draft and publish mutations. When admin login becomes available, the protected Website Settings tab can read drafts. Even an authenticated admin cannot save or publish until this additional server-side switch is explicitly enabled. See [DESIGN_SETTINGS_PHASE4.md](DESIGN_SETTINGS_PHASE4.md).

## Checking whether you entered your Cloudflare keys

You can add your Turnstile site key and secret to the private `.env` **now**, while the admin service stays disabled. Open [admin status](https://drawmeone.com/api/admin/status.php): `app_key_present` and `turnstile_keys_present` report only whether those settings are nonempty (and the app key is long enough); they never disclose the keys. **Presence is not validation** of correct Cloudflare configuration, hostname or token verification. `admin_available` remains false with `DMO_ADMIN_ENABLED=0`. Do not enable it until controlled tests are ready.

## Phase 5: Services & pricing

A new **Services & pricing** panel and `/api/admin/packages.php` are available once admin login works. Editing unpublished tiers requires `DMO_PACKAGE_WRITES_ENABLED=1`. Publishing or editing active public tiers requires both `DMO_PACKAGE_PUBLISH_ENABLED=1` and `DMO_PRICING_APPROVED=1`. All flags default 0. See [PACKAGES_PHASE5.md](PACKAGES_PHASE5.md).
