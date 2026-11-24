# DrawMeOne v2.8–v2.9 — Disposable integration QA and safe public inquiry

Implemented 2026-11-24. None of these tests enable live customer accounts or process money.

## CI integration suite (v2.8)

New GitHub Actions workflow: `.github/workflows/disposable-integration.yml`.

- Each run starts an **ephemeral** MariaDB container and imports the real, committed schema migrations 001, 002 and 003. Database name `drawmeone_ci` and user `ci_user` are guarded in every test.
- `tests/integration_contract.php` inserts synthetic customers, request details, messages, quotes and an unverified order; verifies draft quote visibility, quote ownership and itemized amounts, internal-note exclusion, unpaid order visibility and zero payment rows. All fixtures in this test are rolled back.
- `tests/http_contract.php` starts a localhost-only temporary PHP server with CI-only simulated HTTPS, and checks anonymous rejection, per-customer request visibility, quote and order privacy, CSRF/origin rejection, disabled messaging/quote gates and denied artist actions.
- `tests/http_positive_contract.php` uses fake artist/customer sessions and **CI-only** approved-policy switches. The actual HTTP endpoints create a draft, release an itemized offer, reject cross-owner actions and browser-supplied price overrides, accept once, block replay, confirm a single unpaid order, block duplicate confirmation, exchange customer/artist messages, hide internal notes, reject messages after request completion and verify no payment records exist.
- No live SMTP, third-party Turnstile, payment provider, Hostinger production SQL, real customer address, live uploaded file or private SSH key is used by these tests. They do not validate public onboarding in production.
- Code and synthetic fixtures remain in a public source repository; no true customer data is added.

Verified: [successful synthetic HTTP lifecycle run](https://github.com/pixel375/drawmeone/actions/runs/45417101951). The later [full CI run 45418706387](https://github.com/pixel375/drawmeone/actions/runs/45418706387) also passed, now with a strict `tests/ci_reset.php` between suites and an assertion that unpaid orders count correctly in Artist's Desk. The reset refuses any database except the disposable localhost CI schema.

## Public contact and private source hardening (v2.9)

- The homepage and closed commissions page offer a **mailto:support@drawmeone.com** general inquiry link. The mailbox's inbound/outbound delivery had been separately verified. No form is submitted, no registration is enabled, and no email constitutes an order or payment authorization. The closed-commission panel is visible by default for visitors with JavaScript disabled or temporarily broken, and the existing client script hides it when an authenticated request workspace is available.
- Added independent Apache HTTP-denial files to `tests/`, `docs/` and `.github/`, complementing existing `app/` and `database/` protections. These source files are not public website endpoints.
- The [live read-only SSH diagnostic](https://github.com/pixel375/drawmeone/actions/runs/45417924628) confirmed internal paths are not HTTP-200, public contact is visible, and guarded requests/quotes/orders remain closed. A temporary failed diagnostic was caused by curl's early-exit grep under `pipefail`; the diagnostic was corrected and rerun successfully.

## Known remaining prelaunch tests

- Controlled actual email signup/verification/resend and password recovery with real Turnstile (only after a separate deliberate testing arrangement).
- Browser tests for private-reference upload/removal and web UI at representative desktop/mobile widths.
- Real-image portfolio publication, privacy/consent and description audit.
- Independent offsite encrypted backups, recovery key custody and full scratch MySQL restore.
- Artist-approved Terms, Privacy, licensing/copyright/refunds, then payment provider selection and backend verification.
- Do not conflate passing synthetic CI with public production launch approval.
