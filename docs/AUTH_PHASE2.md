# Draw Me One — Authentication Phase 2 (not enabled yet)

Updated 2026-11-17. Compatible with the imported 001 schema.

## What is implemented in GitHub

- Server-side secure password hashing using PHP Argon2id where available, otherwise PHP's password_hash default, with password_verify and rehash on login.
- Email-verification links stored as SHA-256 token hashes, one-time use, 24-hour expiry. The link token travels in a URL hash fragment so it is not sent in the initial page request.
- Database-backed 7-day customer sessions: a random token in a Secure, HttpOnly, SameSite=Lax, host-only cookie; only SHA-256 hash held in the database.
- Account recovery: one-time 30-minute email link, password replacement, and deletion of all existing sessions.
- Server-side Cloudflare Turnstile Siteverify with exact hostname `drawmeone.com` and action (`signup`, `login`, `reset`). Wrong, reused or expired tokens fail closed.
- Database rate limiting with HMAC-derived keys, never plaintext IP/email addresses. Login, registration, recovery and verification are throttled separately.
- Strict JSON input-size limits, same-origin POST enforcement, HTTPS-only credentialed requests, prepared SQL statements, generic sign-in errors, CSRF checks for authenticated writes, and audit records without secrets.
- Customer role only. **Admin accounts cannot use the customer login** until admin MFA and separate authorization are built.
- The account pages `/account.html` and `/password-reset.html` automatically check auth availability; while disabled, they show a clear **coming soon** state.
- Complete API route allowlisting in `/api/.htaccess` and `/api/auth/.htaccess`.

## Why it is gated

No real user signup or login should be enabled on a public site until outgoing email, Cloudflare keys, rate-limit migration, CAPTCHA, and recovery have been tested in a controlled manner.

By default **all writes are disabled**. The public status endpoint remains readable:

- [https://drawmeone.com/api/auth/status.php](https://drawmeone.com/api/auth/status.php)
- Expected before importing 002: `{"ok":true,"auth_migration_ready":false,"auth_available":false,"registration_available":false,"turnstile_site_key":null,"email_verification_required":true,"commissions_open":false}`. After importing 002, `auth_migration_ready` becomes `true` even though authentication remains intentionally disabled.

These booleans must not be mistaken for an actual registration service.

## One-time SQL migration to import

**Import into the same Hostinger database as 001** using phpMyAdmin:

`database/002_auth_rate_limits.sql`

The migration creates `dmo_rate_limits` (no personal data or passwords) and inserts migration version `002_auth_rate_limits`. It does not alter or remove existing customer records and does not grant authentication access.

Verify in phpMyAdmin (SQL):

```sql
SELECT version FROM dmo_schema_migrations ORDER BY version;
SHOW TABLES LIKE 'dmo_rate_limits';
```

Both migration versions should be present. You do not need a new database or another DB username.

## Additional private .env entries (examples, no real values)

Keep your existing DB_* values. Add **only on Hostinger** to the private `.env`, not GitHub:

```dotenv
DMO_AUTH_ENABLED=0
DMO_REGISTRATION_ENABLED=0
DMO_APP_KEY=your_random_64_character_hex_string
DMO_TURNSTILE_SITE_KEY=your_public_turnstile_key
DMO_TURNSTILE_SECRET=your_private_turnstile_secret
DMO_MAIL_ENABLED=0
DMO_MAIL_FROM=no-reply@drawmeone.com
```

Generate `DMO_APP_KEY` locally: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Keep it private and stable: changing it invalidates CSRF values and rate-limit identities. It must be at least 32 characters long.

**Cloudflare Turnstile:** create a widget for `drawmeone.com` (not the fake Preview site), obtain site key (safe in page) and secret key (private only). Siteverify must succeed from Hostinger PHP with hostname and action validation. Official guide: https://developers.cloudflare.com/turnstile/get-started/server-side-validation/

**Email delivery:** configure a real mailbox under `drawmeone.com` and confirm Hostinger can deliver PHP `mail()` messages and that SPF/DKIM are set. `DMO_MAIL_FROM` must be an actual address ending in `@drawmeone.com`. The backend intentionally will not send without `DMO_MAIL_ENABLED=1`. Before activating, test mail delivery with a controlled address and check both Inbox and Spam. `mail()` accepting a message is not a delivery guarantee.

### Safe activation procedure (later, after reviewing tests)

1. Import migration 002 into phpMyAdmin.
2. Configure Turnstile site key/secret and outgoing email, and create a high-entropy APP_KEY.
3. Confirm CAPTCHA, PHP HTTPS detection, mail delivery, rate-limit behavior, reset, token expiration and session security with a dedicated test user.
4. Set `DMO_AUTH_ENABLED=1` with `DMO_REGISTRATION_ENABLED=0` to enable customer sign-in only (no registrations).
5. Once all end-to-end tests pass, temporarily enable `DMO_REGISTRATION_ENABLED=1` for a controlled signup test. Do not open registration broadly until tests, Terms and Privacy are ready.
6. If problems occur, change both switches to 0; existing DB data remains, but all public account endpoints refuse login.

**We have not done steps 2–5. Leave the switches 0 for now.**

## Routes

| Method | Route | Notes |
| --- | --- | --- |
| GET | `/api/auth/status.php` | Public feature availability and safe site key when configured |
| POST | `/api/auth/signup.php` | Captcha + rate limit + email verification required |
| POST | `/api/auth/verify.php` | Consumes one-time email token |
| POST | `/api/auth/login.php` | Captcha + rate limit; rejects unverified/admin users |
| GET | `/api/auth/me.php` | Customer identity for current session + CSRF |
| POST | `/api/auth/logout.php` | Uses CSRF and revokes session |
| POST | `/api/auth/reset-request.php` | Captcha; generic response prevents email enumeration |
| POST | `/api/auth/reset-confirm.php` | One-time token; changes password and revokes all sessions |

All POST endpoints accept small application/json requests from exact origin `https://drawmeone.com`, over HTTPS only. Neither passwords nor tokens are exposed by GET APIs. Authentication state is kept in secure server-side sessions.

## Planned subsequent work

- Verify this deployment on Hostinger without enabling it.
- Add and run a real PHP unit/integration test suite against a disposable database before activation.
- Complete policies, privacy, encrypted backups, email deliverability test and login/registration QA.
- Separate MFA-protected admin login and permissions.
- Commission submission with authenticated account and owner-checked attachment handling, then secure inbox and quote workflow.
- Payments only after a customer approves the artist's exact quote.

**Do not submit credentials to chat or GitHub.**
