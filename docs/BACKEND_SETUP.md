# Draw Me One — backend phase 1 (read-only PHP / MySQL)

2026-11-16 · Deployed from `pixel375/drawmeone` to Hostinger shared hosting.

## Goal

Validate the server's private configuration and imported SQL schema **before enabling real account creation, quote requests, messages, media uploads or payments**.

All current `commission-preview.html` and `admin-preview.html` controls remain explicitly demo-only. The backend endpoints in this phase are read-only and public-safe.

## Private environment file

Expected default layout:

```text
/home/u123456789/
├── private/
│   └── .env                       # Real credentials only on Hostinger
└── domains/
    └── drawmeone.com/
        └── public_html/
            ├── index.html
            ├── app/               # Private PHP library, HTTP-denied
            └── api/               # Read-only JSON endpoints
```

The code also checks `/home/u123456789/domains/drawmeone.com/private/.env` and an optional server-injected `DMO_ENV_FILE` environment variable. It will **never load an .env from within public_html**. If your real directory structure differs, adjust the private-file location on the hosting account, not the GitHub repository.

**Example only (never commit actual values):**

```dotenv
DB_HOST=localhost
DB_NAME=REPLACE_WITH_HOSTINGER_DATABASE_NAME
DB_USER=REPLACE_WITH_HOSTINGER_DATABASE_USERNAME
DB_PASS="REPLACE_WITH_STRONG_DATABASE_PASSWORD"
```

Values can be enclosed in matching single/double quotes. Do not add inline comments to a password. These four keys are the only keys read by phase 1.

Important: PHP's `getenv()` does **not** load .env files automatically. `app/bootstrap.php` contains a minimal explicit private file loader. It uses realpath, rejects web-root configuration paths, and loads secrets only on the server.

## Verify the actual connection

Once Hostinger has deployed the new GitHub main branch:

1. Open the friendly [backend connection status page](https://drawmeone.com/backend-status.php). It reads the same endpoint and shows only a safe diagnostic stage. For raw JSON, open [https://drawmeone.com/api/health.php](https://drawmeone.com/api/health.php).
2. Healthy database and imported schema:

   ```json
   {"ok":true,"service":"drawmeone-backend","status":"database_connected","api_version":"1","writes_enabled":false}
   ```

3. Missing/unreadable .env, unavailable PDO MySQL, wrong credentials, unreachable database or unexpected PHP environment:

   ```json
   {"ok":false,"service":"drawmeone-backend","status":"setup_required"}
   ```

   This deliberately returns HTTP 503 with **no credentials or SQL errors** in the response.

4. Database is reachable but the expected schema migration is absent:

   ```json
   {"ok":false,"status":"schema_setup_required"}
   ```

   Check in phpMyAdmin: `SELECT version FROM dmo_schema_migrations;`, which should contain `001_initial_schema`.

5. [https://drawmeone.com/api/catalog.php](https://drawmeone.com/api/catalog.php) returns active, explicitly published commission tiers/add-ons from the imported database. A new database contains none, so `tiers` and `addons` should be empty arrays, `requests_open=false`, `currency_pricing_approved=false`. **No placeholder $5–$30 rates are inserted.**
6. [https://drawmeone.com/api/site-settings.php](https://drawmeone.com/api/site-settings.php) returns a public-safe allowlist of published design options, or current Studio/dark defaults when no `published` settings row exists.
7. [https://drawmeone.com/app/bootstrap.php](https://drawmeone.com/app/bootstrap.php) should be HTTP **403 Forbidden**, and direct HTTP access to `database/001_initial_schema.sql.gz` should also be denied.

## Troubleshooting

- If `health.php` is 404: Hostinger has not deployed `api/` from GitHub main yet, or the connected destination folder differs from the served document root. Check Hostinger Git **Deploy** and the target directory.
- If `health.php` is 500: check the site's PHP error log, `.htaccess` compatibility and PHP version (8.2+ recommended). Do not expose phpinfo or detailed exception messages publicly.
- If `setup_required`: confirm the actual Hostinger database host/name/username/password, confirm database user permissions and check `pdo_mysql` in your site's PHP configuration. Check whether PHP's `open_basedir` permits reading the private .env; if not, move it to an allowed directory **still outside public_html** and set `DMO_ENV_FILE` in server configuration.
- The database password is read from Hostinger at runtime; **never paste it into chat, screenshots, frontend JavaScript, or GitHub**.
- If the user created an .env at `public_html/.env`, move it outside public_html immediately; the application deliberately refuses to load it.

## Existing schema contract

The imported migration `001_initial_schema` creates 22 tables, including:

- `dmo_users`, `dmo_auth_tokens`, `dmo_sessions`
- `dmo_commission_tiers`, `dmo_addons`, `dmo_tier_addons`
- `dmo_commission_requests`, `dmo_request_addons`, `dmo_quotes`, `dmo_quote_lines`, `dmo_orders`
- `dmo_payments`, `dmo_conversations`, `dmo_messages`, private references and deliverables
- `dmo_showcase_permissions`, `dmo_portfolio_artworks`, `dmo_artwork_files`, `dmo_site_settings`, `dmo_audit_log`.

The read-only catalog queries the real tier/add-on column names (`estimate_min_minor`, `estimate_max_minor`, `features_json`, `is_active`, `sort_order`), and `site-settings.php` uses the existing `context='published'` row and `settings_json`. Nothing is seeded or modified in MySQL by visiting these endpoints.

## Security boundary for phase 1

- `app/.htaccess` denies all public HTTP traffic into private PHP code.
- `api/.htaccess` allows only `health.php`, `catalog.php`, `site-settings.php`; no writes or catch-all route exists.
- Public JSON responses have `no-store`, `nosniff`, strict framing/referrer headers, and intentionally contain no secrets, user details, private paths or raw SQL error messages.
- Published site settings are **whitelisted**: the API never exposes administrative JSON wholesale.
- `.gitignore` prevents accidental additions of `.env` and local private media. GitHub branch history already contains no credentials.

## Next milestones (not enabled yet)

1. Secure customer auth: password hashes, verified email, rate limiting, secure sessions/cookies, CSRF and mandatory server-verified Turnstile on signup/login.
2. Admin bootstrap and role-bound dashboard authentication with strong passwords, CAPTCHA, rate limiting, short-lived sessions and audit logging (no MFA).
3. Commission quote requests, private reference files, an artist inbox and messaging. No charge before the exact artist-approved quote.
4. Bulk image uploads, optimized variants, consent checks and safe public gallery. Metadata-free batch upload remains the priority.
5. Admin-managed drafts/published design, logo, SEO, tier settings and real payments.

**Do not enable real submissions until all controls are implemented and tested.**

## Setup status codes (safe to share)

- `database_connected`: connection and initial schema OK.
- `private_env_not_found`: PHP can't see/read the private config file. Check layout and PHP open_basedir restrictions.
- `database_config_incomplete`: one of DB_HOST/DB_NAME/DB_USER/DB_PASS is empty or missing.
- `pdo_mysql_unavailable`: required PHP driver missing.
- `database_connection_failed`: PDO can't connect or authenticate; check Hostinger credentials, host and privileges privately.
- `schema_setup_required`: connected database lacks migration 001_initial_schema.
- `setup_required`: unexpected server-side setup problem.

These labels contain no passwords, account names, internal paths, user records or SQL exception messages. The health endpoint still uses HTTP 503 for failed checks. `backend-status.html` displays the safe stage in the browser.


The server-rendered `/backend-status.php` endpoint is preferred for setup diagnostics because it returns readable HTML even when the database health API uses HTTP 503. It shows only a sanitized status code.
