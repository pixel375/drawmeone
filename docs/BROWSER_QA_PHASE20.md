# DrawMeOne v3.2 — Automated multi-layout Chromium QA

Implemented November 24, 2026. [First successful browser run](https://github.com/pixel375/drawmeone/actions/runs/45430891562).

## Public contact fixes

The Studio layout already offered a general inquiry email, but the three completely separate layout templates in `concepts.js` (Atelier, Edition and Signature) still had coming-soon messaging without any usable inquiry link.

All four website styles now include a consistent `mailto:support@drawmeone.com` general inquiry action and a link to the commission information page. Each explicitly says that new bookings and payments remain closed. No account, order, registration, email-send API or payment endpoint was enabled by this change.

The links use responsive, accessible styling, with visible focus indication and reduced-motion support. The source HTML now references updated versions of `concepts.js` and `polish.css` to prevent old cached versions from hiding the links.

## Chromium QA workflow

`.github/workflows/browser-ui-qa.yml` executes on relevant UI changes (and manual workflow dispatch). It:

- Uses a pinned Ubuntu 24.04 runner because the pinned Playwright Chromium release is not compatible with GitHub's then-current Ubuntu 26.04 `ubuntu-latest` image.
- Installs pinned Playwright 1.56.1 and its Chromium browser and starts a local PHP webserver at `127.0.0.1:18868`.
- Mocks **only** public backend availability for deterministic browser-UI checks. It does not create production users, orders, emails or payments.
- Visits Studio, Atelier, Edition and Signature at 1440 × 900, 768 × 1024 and 390 × 844. Verifies gallery counts, demo/sample attribution, contact and information links, absence of document-level horizontal overflow and uncaught JavaScript exceptions.
- Exercises all four layout switches; saved theme/mode/logo state after a page reload; lightbox open, arrow navigation, Escape close; and gallery Load more behavior.
- Verifies commission intake, customer account sign-in and password recovery remain closed when status reports disabled.
- Uses a JavaScript-disabled browser context to verify the closed-commission general inquiry link remains accessible.
- Saves sanitized browser screenshots as short-retention GitHub Actions artifacts; no private customer data or credentials appear in screenshots.

Verified: `BROWSER_SMOKE=PASS` on [run 45430891562](https://github.com/pixel375/drawmeone/actions/runs/45430891562).

## Deliberate limits

This validates real Chromium rendering and interaction **on localhost**, not every browser (especially Safari), production CSS loading under the Hostinger CDN, real email/Turnstile interaction, or authenticated customer/artist screenshots. Those still need separate controlled pilot checks.

Real MariaDB request, quote, order, account recovery and private WebP API integration tests remain a separate existing CI job. Public registration, commission intake, live payments and reference uploads stay disabled.
