# Draw Me One — database starter

**Current schema:** `database/001_initial_schema.sql.gz` (gzip-compressed SQL). The identical, uncompressed `001_initial_schema.sql` is supplied separately as a downloadable attachment in the ChatGPT conversation.

This is a **non-production starter schema**, not a connected application. The static `commission-preview.html` and `admin-preview.html` do not access it. It creates **22 empty tables** and a migration-version record; no user accounts, passwords, payment credentials, customer information, fake orders or live prices are seeded.

## Prepare Hostinger

1. In Hostinger hPanel, go to **Websites → Manage / Dashboard → Databases → Management**.
2. Option A (simple): use **Import database** with the downloadable `.sql` or `.sql.gz`; Hostinger can create the database and user during import. Option B (manual): create a MySQL database, separate database user and a long random password, then open **phpMyAdmin** for that database.
3. In phpMyAdmin, select the **new empty database**. Choose **Import**, select the `.sql` file, click **Go**.
4. Confirm `dmo_schema_migrations` includes `001_initial_schema`. Confirm there are **22 `dmo_*` tables**.
5. Store your generated **database name, username, password and host** in a private password manager. Do not send them into public chat, commit them to GitHub or store them inside the webroot. For Hostinger, the database host is often `localhost`, but use exactly what your hPanel lists.
6. When building the backend, create a secure PHP config file **outside** `public_html` (or platform secret management) with `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`. Use PDO with `utf8mb4`, exceptions and real prepared statements.

Example connection pattern, intentionally **no live credentials**:

```php
<?php
$pdo = new PDO(
    'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME') . ';charset=utf8mb4',
    getenv('DB_USER'),
    getenv('DB_PASS'),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
```

**Not yet ready for use:** we still need secure account creation/email verification, hashed passwords, session/CSRF protection, anti-bot site verification, per-order file authorization, private file storage, messages, admin roles, payment-verification procedures, mail delivery, and tested backups. **Do not activate real registration or quote submissions merely because the tables exist.**

## Security and storage

- This directory includes `.htaccess` requesting that the web server reject HTTP access to migration files even when Hostinger's Git deployment copies the whole repository into `public_html`. Future deployment should move migrations and private PHP configuration outside the webroot entirely.
- Credentials and Turnstile secret must never enter Git or public frontend JS. The Turnstile site key may be public; server Siteverify secret is private.
- Private references, messages and deliverable paths are stored in the database as **opaque storage keys**, not public URLs. Artwork photo uploads must be screened and transcoded before public display.
- All prices/payment amounts are stored as **integer minor currency units** (e.g., cents), not floating point.
- `dmo_site_settings` stores draft/published JSON documents for site layout, logo, default light/dark, packages, SEO, CAPTCHA public key and other editable content. Validate JSON schema in the PHP application; do not put private credentials in this settings document.
- Copyright assignment and client showcase permissions must be collected and audited separately. The default is no publication consent unless explicitly agreed.
- Test a full database backup and restore before production. Record future changes as numbered migrations; do not hand-edit a production schema.

## Verify

In phpMyAdmin → SQL tab, run:

```sql
SELECT version, applied_at FROM dmo_schema_migrations;
SELECT COUNT(*) AS customer_count FROM dmo_users;
SHOW TABLES LIKE 'dmo_%';
```

The first line should return `001_initial_schema`, the second should return `0`. There should be **22 tables**.

## Import troubleshooting

- Import into a **new empty database**, not your existing WordPress or other site database.
- The schema deliberately has no `CREATE DATABASE`, `DROP DATABASE`, `GRANT`, stored procedures, DEFINER, or privileged operations.
- If the import fails, preserve the complete error message and tell us which SQL statement/table failed; don't retry against a partially configured production database without reconciling.
- Hostinger supports `.sql` (and dashboard `.sql.gz`) imports. Compression is provided only to keep the repository's migration snapshot small.
- A successful import does **not** mean the site is connected. Backend integration is the next phase.
