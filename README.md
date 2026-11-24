# Draw Me One — Current development status (November 24, 2026)

The live site at [drawmeone.com](https://drawmeone.com) has a protected Artist's Desk, design controls, bulk artwork management, an optimized gallery and a general-inquiry email link. SMTP is configured and verified. Customer registration and commission intake remain deliberately disabled pending approved Terms/Privacy and controlled onboarding tests. No automatic payments are available.

**Latest implemented phases:** [Private messaging and reference images](docs/MESSAGING_PHASE10.md), [quote review](docs/ROADMAP.md), [unpaid order tracking](docs/ORDERS_PHASE15.md), [encrypted local backups](docs/ENCRYPTED_BACKUPS_PHASE13.md), [launch-readiness dashboard](docs/LAUNCH_READINESS_PHASE16.md), [disposable MariaDB/HTTP integration tests](docs/QA_PHASE17.md), [account recovery hardening](docs/AUTH_RECOVERY_QA_PHASE18.md), and [private image upload tests](docs/REFERENCE_QA_PHASE19.md), [Artist Desk mobile QA](docs/ARTIST_DESK_QA_PHASE21.md), [gallery publishing and bulk controls](docs/ARTWORK_PUBLISH_QA_PHASE22.md), and [full backup recovery testing](docs/FULL_RECOVERY_QA_PHASE23.md).

The integration suite exercises synthetic customers and artist actions end-to-end and is required on relevant backend changes. [Chromium browser QA](docs/BROWSER_QA_PHASE20.md) also covers all four design layouts at desktop, tablet and mobile widths, including gallery interaction, theme persistence and the closed commission/account pages. [Latest passing full integration run](https://github.com/pixel375/drawmeone/actions/runs/45440098692). Live Hostinger diagnostics verified that protected internal files are denied and public contact is visible. [Latest successful live verification](https://github.com/pixel375/drawmeone/actions/runs/45440561280).

**Still required:** real approved portfolio drawings, owner-approved business/privacy policies, private customer onboarding pilot, independent offsite backup/key custody with restore drill, and provider-verified payment processing. Do not enable feature switches on the strength of CI alone.

---

## v1.9 — TLS SMTP mail readiness and policy drafts (customer accounts still closed)

Added an explicit TLS SMTP transport for transactional verification/recovery mail, a private Hostinger configuration workflow, and a rate-limited administrator-only test-email control in Artist's Desk → Website settings. Passwords stay in private Hostinger configuration and GitHub Actions Secrets, not source code. The commission Terms and Privacy draft is **for review only**; customer login/registration/intake remain disabled. See [SMTP setup](docs/MAIL_PHASE9.md) and [draft policies](docs/CUSTOMER_POLICIES_DRAFT.md).

## v1.8 — Verified-account commission requests (not yet open)

A real mobile-friendly commission portal now uses published catalog options and lets verified customers submit text-only, nonbinding requests with rate limits and idempotent retries. Customers can see their own request history; artist notes and unsent quotes stay private. The feature is **disabled by default** pending customer email verification, published policies, and controlled tests. No payments or customer attachments. See [Phase 8 commission intake](docs/CUSTOMER_REQUESTS_PHASE8.md).

## v1.7 — Secure bulk artwork upload, private drafts and public gallery

Artist's Desk now has a real bulk upload queue (JPEG/PNG/WebP), server-side re-encoding to WebP, separate thumbnail/display image variants, private server storage outside `public_html`, and draft/publish/feature/sort controls. The public gallery fetches **published original drawings only**, retaining the demo fallback until real drawings are live. Uploader writes are **off by default** via `DMO_ARTWORK_WRITES_ENABLED=0`. No images or credentials are committed to GitHub. See [Bulk artwork library](docs/BULK_ARTWORK_PHASE7.md).

## v1.6 — Artist request review and private quote drafts

Artist's Desk now includes full commission request details, internal-only notes and versioned quote drafts (CAD/USD, scope, revisions, line items, server-calculated totals), all stored in the existing MySQL schema. No outbound customer message, quote sending or payment. All admin mutation buttons are disabled by default behind `DMO_REQUEST_ADMIN_WRITES_ENABLED=0`. The public site and customer registration stay closed. See [Phase 6 docs](docs/COMMISSION_REVIEW_PHASE6.md).

## Optional Hostinger SSH diagnostic workflow

A **manual-only, read-only** GitHub Actions SSH inspection workflow is included, matching the established model used for CanadaMB. It requires you to configure repository-specific, verified SSH host settings and private-key secrets in the `hostinger-diagnostics` GitHub Environment. It does not deploy, change MySQL or read password values. See [Hostinger SSH diagnostic setup](docs/HOSTINGER_SSH_DIAGNOSTICS.md).

## Admin login diagnostic improvements (2026-11-23)

The Artist's Desk reports distinct, safe failure categories for invalid credentials, rejected Cloudflare CAPTCHA, HTTPS/origin errors, rate limiting and server problems. A read-only `php app/cli-admin.php check email@example.com` command verifies the administrator's password/status locally on Hostinger, without revealing passwords or modifying the database. The server logs whitelisted Turnstile error reasons only, never tokens or secrets. See [Admin login troubleshooting](docs/ADMIN_LOGIN_TROUBLESHOOTING.md).

## v1.5 — Editable commission tiers and add-ons (still gated)

The existing database tables now power a password-protected, role-checked **Services & pricing** editor in Artist's Desk. Create Simple/Normal/Advanced or other custom tiers, configure included features and optional add-ons, set estimates in CAD or USD and deliberately publish or unpublish entries. No sample prices are inserted. New entries remain unpublished. Three private feature switches keep edits and publication disabled pending admin security testing and artist approval. No new SQL import required.

See [Phase 5 commission catalog setup](docs/PACKAGES_PHASE5.md).

## v1.4 — Admin-managed design drafts and publishing

The database-connected Artist's Desk now contains a **protected visual-settings editor** for the approved Studio design, gallery layout, colour palette, dark/light default and the existing logo options. Drafts and published settings are separate rows in `dmo_site_settings`; admin writes remain OFF by default, protected by an additional `DMO_DESIGN_WRITES_ENABLED` private switch, role validation, CSRF, and version checks. The public theme preview is intentionally unchanged until launch. See [Phase 4 design settings](docs/DESIGN_SETTINGS_PHASE4.md).

## v1.3 — Private Artist's Desk, no MFA

New [Artist's Desk](https://drawmeone.com/studio-admin/index.php) with a separate admin login and **actual read-only worklists** backed by MySQL. No fictional admin figures, no write actions. Admin login is **disabled by default**, uses strong password + Turnstile CAPTCHA + rate limiting + secure short sessions, and deliberately **does not require MFA**. The first admin is provisioned only through a protected CLI command (not a public registration form). See [Admin Phase 3 setup](docs/ADMIN_PHASE3.md).

## v1.2 — Secure customer account foundation (closed preview)

Customer auth routes, one-time email verification, password reset, hashed database sessions, HMAC rate limits and server-verified Turnstile integration are staged behind **disabled-by-default** private `.env` switches. The warm personal [account page](https://drawmeone.com/account.html) intentionally shows *coming soon* until configuration/testing is complete. No real registrations or order submissions are enabled.

This phase adds the idempotent [002 rate-limit migration](database/002_auth_rate_limits.sql) for import into your existing database. See [Authentication Phase 2 setup](docs/AUTH_PHASE2.md) and [safe environment example](docs/AUTH_PRIVATE_ENV.example).

## v1.1 — PHP/MySQL backend connection layer (2026-11-16)

Secure read-only PHP endpoints for **database health, approved commission catalog, and published design settings** are now available at `/api/health.php`, `/api/catalog.php`, and `/api/site-settings.php`. Credentials are loaded from a server-private `.env` outside `public_html`. No admin/customer authentication, registration, file upload, messages, quotes or payments are enabled in this phase. See [docs/BACKEND_SETUP.md](docs/BACKEND_SETUP.md).

## v1.0 — Static UX previews and database starter (2026-11-16)

**Live routes:** `/commission-preview.html` (client commission wizard) and `/admin-preview.html` (artist dashboard UX). Both are **public, fictional, noindex, non-functional prototypes**. They make no database requests, create no accounts, take no payments and upload no files. The admin route is **not** a secure real admin panel; never use it for actual business records.

**Database starter:** `database/001_initial_schema.sql.gz` is a gzip-compressed MySQL/MariaDB schema for Hostinger (22 tables and one migration record; no users, passwords, real rates or payment providers configured). An uncompressed importable `.sql` is supplied separately in ChatGPT. Instructions: [database/README.md](database/README.md).

**Why the live footer showed v0.6:** the visible version label had not been updated after v0.6, even when newer changes were present. v1.0 corrects the footer, refreshes asset cache versions, and adds a guarded HTML cache-revalidation `.htaccess` rule. Hostinger Git redeployment should update the live copy.

# Draw Me One

**drawmeone.com** — an original-art portfolio and custom drawing commission website for an independent artist.


## v0.7 — Logo picker and brand assets
The floating **Design Lab → Brand logo** picker provides 10 artist-brand watermark designs plus the original typography choice. Each logo is supplied in **black and white transparent SVG** at `assets/branding/`, named `01-minimal-pencil-black.svg` through `10-creative-palette-white.svg`. The website uses white on dark mode and black on light mode, including on all four website layouts, and remembers the selected logo alongside the other local design settings. The original wordmark remains the default until a logo is deliberately chosen.

For placing watermarks on artwork, use the same black/white variant as appropriate and adjust opacity in the artwork editor; these branding choices do **not** watermark or modify the original portfolio image files. The original high-resolution transparent PNG set is maintained outside this lightweight website repository.

**v0.8 quality fix:** All 20 SVGs were re-traced from the original high-resolution image files using genuine smooth Bézier paths instead of low-resolution straight-line polygons. The image cache version is bumped to `v=8` so existing visitors load the repaired assets.

## v0.9 — Artist-led direction + lightbox fix

The product is now scoped as a **personal hand-drawn character-art portfolio and commissions site**, not an agency. Studio is the main design candidate. Other layout variants remain experiments and should not be exposed as customer choices on launch. Studio has received a smaller, warmer visual treatment.

The desktop/phone gallery viewer now uses a viewport-constrained grid and `object-fit: contain` so full portrait images should fit inside the available stage with navigation and Close always visible; browser testing is still needed.

Owner-supplied drawing collages are **reference-only** and have not been uploaded to this repository. New specification:
- [Commission Product Specification](docs/COMMISSION_PRODUCT_SPEC.md)
- [Art direction & reference notes](docs/ART_DIRECTION.md)

## Current status
- **v0.6 interactive art-studio preview**: four structurally distinct whole-site styles (Studio, Sidebar, Journal, Immersive), five independent color themes (Noir, Ocean, Paper, Aurora, Ember), three gallery layouts (Masonry, Mosaic, Horizontal Scroll), dark/light mode, remembered preferences, 24 self-hosted demonstration images, responsive design and lightbox. Demo visuals are clearly labelled as examples and automatically disappear once real approved artwork is added to `data/portfolio.json`.
- Quote/request, payment, contact and admin tools are **not active yet**. We will not accept customer personal data or payments before an agreed secure implementation.

**Read first:** [Development roadmap](docs/ROADMAP.md) · [How to add approved artwork](docs/ARTWORK_GUIDE.md)

## Current stack and hosting
Static HTML/CSS/JS deployed from the root of GitHub `main` to Hostinger shared hosting. No Node runtime, backend, database, build step, secrets, or API tokens needed yet.

- `index.html` — public landing page
- `styles.css` — design and responsive styles
- `gallery.js` — validated, accessible image gallery and full-screen viewer
- `studio.js` — live theme, gallery layout, dark/light controls, mobile lab auto-collapse, and page-scroll progress
- `concepts.js` — three independently templated Atelier, Edition, and Signature sites plus switching logic
- `concepts.css` — structural design for the three alternative sites
- `polish.css` — v0.6 refined typography, subtle interaction details, mobile controls and gallery-specific visual safeguards
- `data/portfolio.json` — **real artist work only**, currently `{"works":[]}`
- `data/demo-portfolio.json` — 24 locally hosted demonstration SVG artworks used only while the real portfolio is empty
- `assets/demo/art-XX.svg` — locally stored demo visuals (zero external photo dependency)
- `assets/demo/placeholder.svg` — local image-error fallback
- `assets/portfolio/` — artist-approved original work for public display
- `robots.txt` and `sitemap.xml` — initial search discovery files
- `docs/` — planning and publishing guides

### Future architecture
Hostinger-supported PHP + MySQL/MariaDB with PDO, secure admin login, upload processing, server-side-rendered portfolio images, quote requests, and hosted payment checkout. Keep private commission references, authentication secrets, and full-res masters out of GitHub and the public webroot.

## Release safety
No unapproved artwork, prices, personal addresses/emails, fabricated testimonials, real customer contact forms or payment handling without the artist/project owner's sign-off.

## v0.4 interactive design lab
Visit https://drawmeone.com/ on desktop or mobile and expand the floating **Design Lab** panel.

### WEBSITE STYLE — the whole site's layout
1. **Studio:** balanced split hero, section-based browsing and guided story.
2. **Sidebar:** sticky left navigation on desktop and portfolio-first page structure.
3. **Journal:** oversized editorial masthead and typography, panoramic cover and horizontal process cards.
4. **Immersive:** full-screen cover, floating navigation and cinematic gallery presentation.

### COLOR THEME — independent of website style
- **Noir** (black, purple), **Ocean** (navy, cyan), **Paper** (warm neutral), **Aurora** (deep green, lime), and **Ember** (burgundy, orange).
- Dark and light color modes, with the visitor's choices persisted locally.

### GALLERY — independent of website style and color
- **Masonry**: natural aspect ratios, image-column arrangement.
- **Mosaic**: editorial grid with consistent cells, preserve complete art via object-fit contain.
- **Scroll**: horizontal, touch-friendly scroll-snap gallery.
- For Masonry/Mosaic, initially show **12 images on desktop / 8 on mobile** (roughly four rows); each **Load more images** click adds the next 12/8. The horizontal Scroll view shows all images.
- All 24 demo items are loaded from `assets/demo/` on our own Hostinger server, not Picsum or an external hotlink. When any valid artist-approved works are entered in `data/portfolio.json`, the demo gallery is automatically replaced by authentic artwork. Demo files never appear as real portfolio entries.

**Temporary SEO status:** the home page has `noindex,follow` for the design-testing period so Google doesn't index placeholder content. Remove this only when authentic work and live commission information are ready. No contact forms, personal-data collection, payment acceptance or backend features are active yet.

## Release safety
No unapproved artwork, prices, personal addresses/emails, fabricated testimonials, real customer contact forms or payment handling without the artist/project owner's sign-off.

## v0.3 interactive design preview

Open https://drawmeone.com/ on desktop or mobile. Use the floating **Design Lab** panel to compare:

- **Noir**: clean dark minimalism, defaults to Masonry.
- **Canvas**: cinematic full-image presentation, defaults to horizontal Scroll.
- **Editorial**: sophisticated serif/type treatment, defaults to Mosaic.
- Switch among the **Masonry / Mosaic / Scroll** gallery layouts independently of the design.
- Switch **Dark / Light**, or use the sun/moon toggle in the header.
- The panel can be minimized. Theme/layout/mode preferences are saved in browser local storage; no tracking is performed.

**Preview-only policy:** Sample photographs are fetched from `picsum.photos` and are not the artist's work. They will automatically disappear once real approved artwork is published. A generic local decorative SVG is used if the sample photos fail to load. Because this version deliberately contains unrelated sample photos, the home page currently uses `noindex,follow` to avoid being indexed with inaccurate image content. **Remove noindex after the genuine gallery and business content are launch-ready.**

This is a front-end preview, not a finished commission checkout or private media manager. No customer data is collected.

## v0.5 — Three completely redesigned website concepts
Based on owner feedback that the alternate layouts looked like basic templates, the entire page structure of **Sidebar, Journal and Immersive** was discarded and rebuilt as three new page architectures. **Studio remains the original layout** for a baseline comparison.

1. **Atelier** (internal id `archive`): bespoke boutique art studio, permanent desktop identity/navigation rail, custom split hero, image collage, manifesto and strongly defined commission process, impactful contrasting contact section.
2. **Edition** (internal id `journal`): art publication / exhibition website with huge typography-driven masthead, magazine opening spread, featured visual studies, collection-first editorial presentation, typographic commission guide and final-page close.
3. **Signature** (internal id `cinema`): cinematic brand website with floating navigation, layered full-screen demo visuals, high-impact hero, spacious manifesto, image theatre, premium process cards and an oversized closing invitation.

### Technical approach
- Three independently templated `<main>` layouts in `concepts.js`, with isolated styles in `concepts.css`. These are **different semantic HTML page architectures**, not simply reordered sections or recoloured styles.
- The original Studio `<main>` remains unmodified and is toggled only when it is selected.
- Live gallery and Load more nodes are moved between templates (not duplicated), retaining interactions and image-only publishing.
- The current theme, light/dark, and gallery layout controls work across all four site styles. No payment or customer data is collected.
- Every template uses self-hosted sample illustrations and accurately identifies them as samples, not the artist's portfolio. No fake reviews, ratings, order counts or unsupported promises of completed work.
- `<meta name="robots" content="noindex,follow">` remains while the site is an unfinished design preview.

### Validation checklist
- [ ] Check Studio remains visually unchanged from v0.4 and still loads images.
- [ ] Verify Atelier / Edition / Signature show different navigation, header, cover, portfolio framing, commission explanations and footers.
- [ ] Confirm automatic movement of the same gallery DOM works when switching styles repeatedly.
- [ ] Test both desktop and mobile responsive behavior; check dark/light and all five themes.
- [ ] Verify Hostinger deployed the latest main-branch commit and CSS/JS assets.

## v0.6 — professional polish, performance and automatic art replacement

- **Artist artwork automatically replaces every demo image**, including *hero covers and featured image compositions*, rather than only replacing the portfolio grid. In the preview, the sample artwork is clearly labeled as such. When actual entries appear in `data/portfolio.json`, the first images are used for the feature visuals, all sample labels disappear, and original artwork is shown uncropped and without colour filters. This avoids claiming any placeholder designs as authentic.
- Existing **12/8-image Load more** behavior is kept. Rendering now appends new cards to the existing DOM rather than recreating loaded cards; switching gallery layout remains supported.
- Typography: shifted Google Fonts loading from CSS `@import` to one HTML stylesheet, with preconnect hints for faster font delivery; local serif/system fallbacks remain.
- Dedicated `polish.css` refines rhythm, typographic choices, navigation feedback, image treatment, dark/light contrast, and each unique design without reworking the original Studio.
- The desktop design lab gets clear site-choice thumbnails/markers and succinct descriptions. On narrow screens, it starts minimized (or recalls a session preference), expands by tap and minimizes after a selection; it is designed not to obscure the artwork.
- A three-pixel reading-progress line helps users navigate long image-led pages.
- Reduced-motion handling, visible keyboard focus, lightbox touch actions and minimum touch target sizes retained.
- SEO: site remains temporarily `noindex,follow` during this incomplete portfolio preview. The final real gallery should use server-rendered image markup from the admin database, no fabricated proof or client references, and accurate metadata.

### Manual QA
Verify Studio / Atelier / Edition / Signature; demo vs real images; dark/light; theme combinations; initial/load-more counts; viewport widths 390, 600, 800, 1365; keyboard and swipe lightbox; page speed; fallback offline fonts. Do **not** treat this static preview as a functioning commission-request application.
