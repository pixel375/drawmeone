# Draw Me One — Product Roadmap
Updated: November 2026
Status: design direction / foundational implementation

## Product vision
Draw Me One is an artist-led website that converts admiration for original drawings into clear, trusted, paid custom commission requests. The original artwork is central; the user experience must feel distinctive, fast, accessible, and personal.

## Confirmed requirements (from project owner)
- Domain: https://drawmeone.com
- Hosting: Hostinger shared hosting, deployed from GitHub `pixel375/drawmeone` (main).
- Gallery must contain **only drawings created by the artist**.
- Artwork bulk upload should require **no image title, description, caption, tags, or other typing**; the artist should be able to select many files and publish with one action. Public site text (headlines, commission details, CTAs, policy information) is welcomed and encouraged. The public gallery should remain image-forward with minimal visual clutter.
- Artist must eventually be able to upload/manage art without GitHub access through an authenticated dashboard and database.
- Site sells custom drawing commissions, not generic print-on-demand by default.
- Visitors need a way to submit commission requests and pay; exact provider/checkout flow undecided.
- Contact methods to evaluate include email, Discord and alternatives.
- Strong visual polish, differentiated brand, mobile accessibility, excellent search discoverability.
- Current visual preference (pending final direction): **modern, mobile-first, dark-first**; light mode available with a user-controlled switcher, potentially a hover-expanded floating control on desktop, with tap/click equivalence on mobile.

## Design principles
1. **Art first:** immersive but lightweight asymmetrical gallery; native image aspect ratios; no overlays. Any descriptive details are hidden in accessible HTML / alt metadata and optional standalone detail pages, not baked into the visible artwork.
2. **Authenticity:** artist-approved artwork only, no stock/AI artwork as purported work samples, no fake testimonials, prices, turnaround times or social handles.
3. **Visual quality:** expressive editorial typography, tasteful motion, calm backgrounds and accents derived from the artist's work. Avoid distracting artwork styling.
4. **Clarity:** visitor understands pricing model, request process, rights and timeline before payment.
5. **Privacy:** reference images and private communications never publicly indexed.
6. **Speed:** responsive WebP/AVIF derivatives, lazy-load below-fold images, accessible keyboard controls, reduced-motion support.
7. **SEO:** crawlable public portfolio pages, useful image alt text and filenames, descriptive metadata outside artwork, structured data only where valid, sitemaps, canonical tags, Search Console, PageSpeed testing.

## Recommended visitor funnel
Discovery -> Explore portfolio -> See commission options / approximate prices -> Submit brief + references -> Artist reviews -> Quote accepted -> Pay deposit/full amount using hosted checkout -> Artist delivers -> Client confirms / approved work may join portfolio.

IMPORTANT: request != accepted order; payment must not occur before a confirmed scope and quote for custom work by default.

## Phases & acceptance criteria
### P1 — Portfolio foundation (IN PROGRESS)
- [x] First static landing page and Hostinger Git deployment.
- [x] Add gallery component that renders image-only art from a validated manifest, with responsive masonry layout and accessible modal viewer.
- [x] Create artwork content guidelines for later upload.
- [ ] Receive and publish at least 6-12 **approved real drawings** in optimized variants (no manual title or descriptions needed).
- [ ] Replace generic decorative hero doodle with commissioned/approved art or artist-provided branding.
- [ ] Evaluate gallery on real mobile devices, images with varied portrait/landscape ratios and keyboard input.
- [ ] Eventually move public gallery rendering to server-rendered PHP HTML for crawlability once database goes live.

### P2 — Commission information and inquiry workflow
- [ ] Determine offered art styles, delivery format (digital vs physical), complexity tiers, turnaround, revision limits and pricing.
- [ ] Publish transparent "How it works" and FAQ plus approximate starting prices where approved.
- [ ] Build a secure commission brief form with type, brief, optional private reference images, optional deadline, budget, consent, preferred contact and anti-spam protections.
- [ ] Send private receipt to artist and acknowledgement to customer. Do not expose private references publicly.
- [ ] Optional "currently accepting commissions" toggle and capacity/queue indicator.

### P3 — Payments and order tracking
- [ ] Decide Stripe / PayPal / Buy Me a Coffee / provider combination, country, currency, fees, buyer preferences.
- [ ] Start with reviewed custom quotes and provider-hosted invoice or payment link; don't take card numbers on-site.
- [ ] Set policies on quote validity, deposit, cancellation, revisions, refund handling, copyright, personal/commercial use, and permitted subjects.
- [ ] Connect payment events with verified server-side webhooks or a manual-confirmation process; never trust a browser return page as proof of payment.
- [ ] Send receipt and order status updates; avoid premature payment before acceptance.

### P4 — Secure admin and database
- [ ] Backend: PHP (supported on Hostinger shared hosting), MySQL/MariaDB with PDO; no persistent Node server dependency.
- [ ] Admin: hashed password credentials, no MFA, HTTPS, secure sessions, CSRF protection, rate limits, login alerts, backup/recovery.
- [ ] Media: validate extension + actual image MIME, dimension/size limits, re-encode and strip metadata, safe non-executable public image folder, originals stored privately, generated thumbnails.
- [ ] Admin actions: **zero-metadata-required bulk drag/drop** (automatic filenames/dimensions/previews/optimized variants), one-click publish, progress/errors/retries, optional editable alt text/tags, reorder, draft/publish, edit/remove, audit actions.
- [ ] Commission inbox and statuses; restricted private reference storage; payment status tracking.
- [ ] DB migrations, secure local configuration outside public web root, backup/restore drills.
- [ ] Admin endpoints blocked from crawling/indexing; auth must enforce real access control.

### P5 — SEO and launch quality
- [x] Initial canonical/homepage metadata and base sitemap/robots.
- [ ] Art-specific landing pages with server-rendered `<img>` and descriptive text **outside artwork images**.
- [ ] Appropriate `WebSite`, `Person` or `Organization`, and art `ImageObject` schema where factually accurate, with verified fields.
- [ ] Image sitemaps generated from published works; social preview using approved art.
- [ ] Verify domain and submit sitemap in Google Search Console; measure coverage and Core Web Vitals.
- [ ] Accessibility audits, color contrast, touch-target checks, responsive performance, security review, legal/policy pages.
- [ ] Launch QA across Chrome/Safari/Firefox and mobile.

## Candidate delights (not committed requirements)
- "Open for commissions" capacity badge that is admin controlled (not fabricated).
- Dynamic featured-piece or editorial-style hero.
- Category filters **outside artwork** if genuine categories are supplied.
- Private client proofing page requiring unique authenticated/signed access.
- Optional commission queue estimate and progress tracker.
- Gift commissions, digital gift cards, limited seasonal slots.
- Opt-in email announcements for new work.
- Customer reviews only when real, permissioned and attributable.

## Choices pending artist/user input
- Final design choice among modern dark-first directions (e.g. Minimal Noir, Neon Atelier, Editorial Dark, Immersive Canvas); finalize typography, accents, dark/light palettes, brand assets.
- First sample images and permissions to publish.
- Art medium, categories, licensing, pricing/currency, revision policy, delivery format, turnaround and capacity.
- Contact endpoints (dedicated business email recommended as primary; Discord secondary).
- Commission request workflow and payment provider; whether 50% deposit is appropriate.
- Country of business/payment account, taxes and invoicing requirements.
- Whether real name/location should ever be published; do not invent them.

## Technology strategy
Start as simple static HTML/CSS/JS without downtime. Switch public pages to progressively enhanced PHP templates when the server-backed dashboard is introduced. This allows server-rendered image markup (stronger SEO) and secure handling of reference files. Hostinger's web hosting supports PHP, PDO/MySQL and MariaDB; do not assume managed workers, queues or Node services. Images and original assets are separate from the database; the DB stores metadata and access controls. No payment credentials in the repository.

## Release rules
Do not represent commissions as open, embed third-party payment SDKs, show a fabricated price, expose a real contact method, collect personal information, or publish unapproved portfolio images without approval. Deploy incremental safe iterations to main after review and verification. Document migration steps before changing the live static entry point.

## Design-selection note — 2026-11-14

The original pastel/hand-drawn landing page is a *temporary proof of concept*, not an approved final visual direction. **Do not restyle the live site to a new theme until a concept is chosen.**

Four proposed modern/mobile-first styles:
1. **Minimal Noir**: charcoal (#111317), clean ivory text, restrained electric-violet accent; big artwork, tight minimal UI, lots of negative space.
2. **Neon Atelier**: near-black (#090b11), controlled indigo/cyan/coral glow only on controls; crisp glass-like navigation; balanced motion, never distract from art.
3. **Editorial Dark**: ink (#16161a), gallery-white type, soft sepia accents, bold editorial typography and full-bleed images, magazine-like composition.
4. **Immersive Canvas**: dark neutral (#101012), almost edge-to-edge images, small floating controls, cinematic transitions, accessible lightbox.

Implement responsive behaviour primarily for mobile screens; desktop effects are optional progressive enhancements. Theme control: a small floating or header moon/sun button which may reveal options on desktop hover/focus but must work on click/tap/keyboard. Persist explicit selection, provide dark and light palettes, avoid page flash on reload, honor reduced motion and adequate contrast. Should initially prefer dark or follow device preference as decided with the artist.

## Build history — 2026-11-14, v0.3 interactive preview

Implemented and deployed through the existing main-branch GitHub-to-Hostinger flow:
- [x] Three interactive design directions: Minimal Noir, Immersive Canvas, Dark Editorial.
- [x] Three independent layouts: Masonry (intrinsic heights), Mosaic (contain art within grid cells), Scroll (horizontal snap gallery).
- [x] Dark/light theme switcher accessible by touch, keyboard and pointer, remembers settings in browser.
- [x] 10 **explicitly labeled demo photographs** served by Picsum; wholly separate from artist-only `data/portfolio.json` and hidden automatically when authentic artwork is uploaded. Fallback SVG for image loads.
- [x] Mobile responsiveness and keyboard/swipe navigation in lightbox.
- [x] Homepage temporary `noindex` flag during placeholder-photo preview; must be **removed when the site is ready to launch with actual artist artwork**.
- [ ] Get feedback on which theme/layout combinations work best across desktop and mobile; then refine typography, color, proportions, and image treatment.
- [ ] Before launch: replace all demo content with approved artist drawings and ensure accurate OG image, image metadata, crawlability, FAQ, and privacy/legal policies.

Testing note: browser validation of the live Hostinger site may be restricted by automated-access 403 responses even when the user sees the site. GitHub branch commit success does not independently prove deployment completion.

## v0.4 — 2026-11-15, first-pass design feedback and delivery

**Reported feedback:** external dummy image URLs fail to load; masonry/mosaic should initially show only about 4-5 rows, then progressive reveal; add two more color themes; provide 3-4 genuinely distinct complete website layouts.

**Implementation:**
- [x] Replace all external Picsum image dependencies (including hero) with **24 local, original SVG demonstration graphics** stored under `assets/demo/`. They are visually labeled as demos, NOT the sister's art. Safe no-external-image fallback remains.
- [x] Render 12 images initially on desktop (approx. four rows at three columns), 8 on mobile (approx. four rows at two columns). **Load more images** progressively reveals 12/8 more. A full-width fade/crop is deliberately avoided: it would cut drawings in half.
- [x] Exempt horizontal Scroll gallery from progressive cutoff. Provide visible count and accessible button status.
- [x] Five independent color themes: Noir, Ocean (formerly Canvas), Paper (formerly Editorial), Aurora, Ember, all with dark/light palettes.
- [x] Four **structural** site layouts: Studio split, Sidebar gallery-first sticky desktop navigation, Journal magazine cover and three-column steps, Immersive full-viewport cover.
- [x] Store website style + theme + layout + dark/light in local browser storage. Choosing a whole-site style selects an appropriate default gallery format, but gallery layout can then be adjusted independently.
- [ ] Verify live deployment, JS gallery rendering, responsive behaviour and toggles in browser.
- [ ] Collect user's favourite combinations before removing design lab from public launch.
- [ ] Get approved real artwork; samples must stay temporary and unindexed.

## v0.5 — Creative overhaul (2026-11-15)

The owner judged the three alternate website styles too close to free WordPress templates. Studio is explicitly exempt from the redesign.

- [x] Keep Studio as original, fully functioning fallback for comparisons.
- [x] Rebuild Sidebar as **Atelier** — real left rail, striking staggered display typography, bespoke image stage, clear process and bold contact finish.
- [x] Rebuild Journal as **Edition** — oversized art-publication masthead, editorial cover spread, exhibition-style work section, three-step commission chapter.
- [x] Rebuild Immersive as **Signature** — edge-to-edge cinematic hero, floating glass navigation, large manifesto, spacious gallery and premium story cards.
- [x] Separate DOM templates + isolated CSS; share the same actual portfolio viewer and load-more gallery with no duplicate image records.
- [x] Retain all five theme palettes, dark/light switcher, three gallery layouts and 24 local placeholders.
- [ ] Desktop live browser QA for all layout toggles and gallery interactions.
- [ ] Phone viewport QA and polish.
- [ ] Evaluate new layouts with actual artwork before selecting a final brand design.

**Credibility policy:** Never claim demonstrated quality, ratings, customer feedback or established delivery timelines before verified artist work and genuine customer evidence are available. Use precise, forward-looking process explanations while commissions remain closed.

## v0.6 — polish and product readiness (2026-11-15)

- [x] Keep 4 distinct HTML site concepts and 5 colour themes; improve each with more intentional type, spatial rhythm, micro-interactions, and a visual identity.
- [x] Eliminate CSS `@import` font delay; replace with one consolidated font link and preconnect in HTML.
- [x] Add reading-progress indicator to long pages.
- [x] Optimize load-more to append new images only; avoid reloading gallery on each reveal.
- [x] Automatically replace demo imagery in all hero compositions (not just gallery) when real portfolio manifest has valid entries; hide sample labels. Keep real artwork colour-correct and avoid cropping in hero features.
- [x] Improve design lab accessibility and screen-space usage on phones; persist collapse state per browser session.
- [x] Add focus/hover treatments, additional reduced-motion support, and avoid images changing saturation when genuine art is displayed.
- [ ] Run live browser QA for all style switches, light/dark, newly loaded assets and load-more.
- [ ] Check real 390px phone viewport visually; cloud browser may not allow viewport resizing.
- [ ] Replace abstract placeholder art with artist-authorized originals before launch and verify actual performance in PageSpeed Insights.
- [ ] Build secure PHP+DB admin uploader and inquiry workflow with payment provider after approval.

**Note:** Honest creative credibility requires verified art, real process details, approved pricing and actual client feedback. Do not invent any of them. In-preview status and `noindex` remain on.

## v0.9 — 2026-11-16: Owner requirements and pivot to artist-first site

Owner supplied four original-work reference collages to communicate style. **Do not publish or upload these pictures anywhere.** They show hand-made character portraits (traditional coloured-pencil, ink and digital), anime/game-style and original character art. The studio is a single independent artist, not an agency or generative image business.

**Current direction**: Studio is the only broadly acceptable website style. Alternate Atelier/Edition/Signature are oversized, visually messy and unapproved. Preserve for design experiments only, not as public customer choices. Future concepts should be warmer, smaller scale and sketchbook-inspired; do not build more giant generic landing pages. Visitor controls will ultimately be restricted to light/dark. All other design, pricing, media, SEO, CAPTCHA and page content settings belong in the private admin.

### Frontend fix and light polish
- [x] Fix portrait-image viewer by constraining entire image to the available central grid row of a viewport-height dialog, while keeping Close and previous/next controls permanently visible; include reduced-height viewport rules and safe-area insets.
- [x] Reduce Studio hero and section typography and oversized vertical spacing; humanize headline/copy without inventing an artist bio or publishing private works.
- [ ] **Test** portrait fit on actual desktop viewport with tall aspect ratio and browser zoom; confirm with user.
- [ ] Retire or replace existing alternate website styles only after new art direction approved.
- [ ] Obtain publication approval before substituting any user-supplied pictures for local dummy art.
- [ ] Test Scroll versus Masonry with approved real works; do not crop images in zoom/lightbox.

### Business model and specification
- [x] Record owner-suggested estimated Simple $5–$10, Normal $15–$20, Advanced $25–$30 tiers, features and add-ons as editable administrative configuration. Prices/currency are examples **not approved for public display**.
- [x] Document commission flow: order CTA → option selection and estimate → brief and private references → account creation at submit → artist review → exact quote → acceptance → external/manual payment → status updates, revisions and digital delivery.
- [x] Document users/accounts, onsite order messages, external Discord link, email, quote records and customer inbox.
- [x] Document admin-first information architecture: **work/orders/messages first**, artwork uploads second, website/config/security settings third.
- [x] Capture payment preference PayPal personal for commercial Goods & Services, Interac for Canadian bank customers, optional Buy Me a Coffee support link; no automatic paid status without verified evidence.
- [x] Capture desired buyer ownership, applicable Canadian signed copyright assignment, separate moral rights, and third-party fan-art limitations.
- [x] Capture portfolio consent option with owner-suggested default checked. **Recommended safer default unchecked; owner to confirm** before launch; private artworks should never appear publicly without explicit consent.
- [x] Require Turnstile on signup/login/quote forms, with mandatory server validation plus CSRF, secure sessions and throttling.
- [x] Proposed secure shared-hosting PHP/MySQL architecture and database entity inventory documented in `docs/COMMISSION_PRODUCT_SPEC.md`.
- [ ] Review/approve actual pricing, currency, tiers and business terms with artist.
- [ ] Confirm order ownership/rights contract and public showcase permission default with appropriate legal review.
- [ ] Prototype mobile-first commission form and admin dashboard (without collecting real customer data), then implement backend.

See `docs/ART_DIRECTION.md` and `docs/COMMISSION_PRODUCT_SPEC.md`.

## v1.0 — 2026-11-16: Prototypes + database preparation

- [x] Deliver a public **non-functional** customer commission UI preview: provisional Simple/Normal/Advanced selections, optional add-ons, request brief, subject, preferred contact, deadline, review and an explicit demo-only completion; **no real passwords, customer uploads, or network submissions**.
- [x] Deliver separate **fictional admin UX preview** organized as work (overview, requests, messages), art uploads, and site/admin settings (pricing, theme, users, SEO, CAPTCHA/payment planning).
- [x] Provide 22-table MySQL/MariaDB schema in `database/001_initial_schema.sql.gz` and an uncompressed importable SQL file to user separately. No accounts/payment credentials, actual pricing or real customer records.
- [x] Add Hostinger setup instructions and keep database migration files inaccessible from the public website through a database-folder `.htaccess`.
- [x] Add demo links in Design Lab and update stale visible footer label from v0.6 to v1.0.
- [x] Configure HTML/JSON revalidation so future deployment changes are not masked by a stale HTML cache.
- [ ] Verify GitHub push deployed and both live prototypes display/function on Hostinger.
- [ ] Confirm Hostinger MySQL/MariaDB version and import schema via phpMyAdmin, keeping the password entirely private.
- [ ] Build secure PHP backend endpoints and complete a legitimate signup / quote / messaging / bulk-upload workflow only after end-to-end tests.

## v1.1 — 2026-11-16: First real backend connection

- [x] Implement PHP 8.2+ private environment loader searching standard Hostinger private directories; reject all configurations under public_html.
- [x] Implement PDO MySQL with utf8mb4, UTC timestamps, strict exceptions, native prepared statements, 5s connection timeout.
- [x] Add GET /api/health.php verifying 001_initial_schema; response never exposes secrets or raw SQL errors.
- [x] Add GET /api/catalog.php for explicitly active tiers/add-ons; new database returns empty catalog, never invented prices.
- [x] Add GET /api/site-settings.php exposing only whitelisted published visual settings, no administrative secrets.
- [x] Add public/private route protection via .htaccess and prevent accidental secret commits via .gitignore.
- [x] Document database setup and safe verification in docs/BACKEND_SETUP.md.
- [ ] Check actual Hostinger deployment and response of /api/health.php; diagnose any configuration mismatch without requesting user's password.
- [ ] Build subsequent authenticated write endpoints only after email, CSRF, Turnstile Siteverify and brute-force controls are ready.

## v1.2 — 2026-11-17 · Secure customer account foundation (gated)

- [x] Reuse 001 user/token/session tables for registration, email verification, session login, logout and password reset.
- [x] Implement Cloudflare Turnstile Siteverify with hostname/action validation on signup/login/reset requests; no client-only CAPTCHA trust.
- [x] Add migration 002 HMAC rate limiting, separate IP and email bucket, no plaintext IP stored.
- [x] Implement strict same-origin, HTTPS and JSON checks for POST, secure cookies, hashed sessions and CSRF requirement on logout.
- [x] Customer login deliberately denies admin/staff roles pending separate admin authentication.
- [x] Add live account and recovery pages that stay closed until security/SMTP/mail integration is configured and enabled.
- [x] Implement disabled-by-default private switches DMO_AUTH_ENABLED and DMO_REGISTRATION_ENABLED; no real writes on deployment.
- [ ] Import migration 002 into Hostinger MySQL (via phpMyAdmin) and verify.
- [ ] Configure and test Turnstile, real sending email, secure HTTPS detection and end-to-end auth flows in a controlled test account.
- [ ] Implement independent admin authentication/roles (no MFA) and audit verification, plus private commission request endpoints.

## v1.3 — 2026-11-17: Private admin access and read-only real dashboard (no MFA)

- [x] Confirm migration 002 installed and database is connected.
- [x] Respect owner's explicit choice: **do not require MFA**, authenticator applications, TOTP, passkeys or recovery codes for administrator login.
- [x] Add independently gated admin login requiring strong password, Cloudflare Turnstile validation, rate limiting and role=admin; not accessible through customer login.
- [x] Add 12-hour maximum admin sessions, 30-minute idle timeout, HttpOnly/Secure/SameSite Strict cookie, CSRF-protected logout and audit trail.
- [x] Add CLI-only first-admin setup and emergency password reset. No public route to elevate users into admin role.
- [x] Add real read-only admin worklist: actual request counts, active orders, payments needing manual verification and message activity. No fake numbers.
- [x] Add admin-only request/message listings with strict session checks, no file-storage secrets.
- [x] Provide separate live admin page at /studio-admin/index.php. Admin accounts remain **disabled by default** pending configuration/testing.
- [ ] Configure private DMO_ADMIN_ENABLED and Turnstile credentials; test controlled admin sign-in, session expiry and audit before enabling.
- [ ] Implement authenticated commission submissions, private references and artist quote actions once user-facing policies are approved.
- [ ] Implement image uploader and appearance settings with versioned draft/publish controls.

## v1.4 — 2026-11-17: Admin-only design drafts and publish

- [x] Add strict allowlist for Studio-only live layout, five palettes, three gallery styles, two visitor default modes, and original/10 SVG logos.
- [x] Add admin-only GET/POST design editor, with draft/published rows, CSRF, version conflict handling, database transaction, and audit trail.
- [x] Add artist dashboard controls for settings editing behind a separate **disabled-by-default** DMO_DESIGN_WRITES_ENABLED flag.
- [x] Expand public site settings API with validated published logo; never expose drafts, secrets or arbitrary JSON.
- [x] No new SQL migration or additional database user required for this phase.
- [ ] Run active admin login + draft/publish end-to-end QA once user has configured Turnstile and manually provisioned a test admin.
- [ ] Transition homepage from localStorage Design Lab to published defaults; keep visitor light/dark only. Do not do this until the owner approves public UI.
- [ ] Editable commission tiers, add-ons, SEO and art uploader next.

## v1.5 — 2026-11-17: Admin-managed commission tiers and add-ons

- [x] Ground tier/add-on implementation in actual 001 schema (id, slug, descriptions, CAD/USD minor-unit ranges, features JSON, active/sort fields).
- [x] Build protected GET/POST admin API with editor for tiers, add-ons, features, estimates and ordering. New entries unpublished; no hard-coded sample prices.
- [x] Require verified admin role/session, CSRF, strict inputs, prepared statements and transaction/audit for mutations.
- [x] Three private gates: DMO_PACKAGE_WRITES_ENABLED, DMO_PACKAGE_PUBLISH_ENABLED, DMO_PRICING_APPROVED. All default OFF. Editing an active public item requires publication gate.
- [x] Allowlist admin route; no public POST to catalog, no auto-quote/checkout, no direct deletes.
- [ ] Verify authenticated CRUD in controlled setup after Turnstile login QA; confirm pricing/currency with artist before publishing.
- [ ] Wire public commission page to real published catalog and secure logged-in request submission after terms and uploader security work.

## v1.6 — 2026-11-23: Private commission review and quote drafting

- [x] Authenticated full request detail (brief, contact, tier/addons, messages, quotes) with no private file storage keys leaked.
- [x] Artist-only internal notes in existing conversation/message schema; never customer visible, no outbound send.
- [x] Server-calculated versioned draft quotes with detailed line items and audit events; no sending/accepting/payment operations.
- [x] Add Orders & quotes and Messages deep links to the request; responsive accessible admin forms and history view.
- [x] All mutation operations remain disabled by default with DMO_REQUEST_ADMIN_WRITES_ENABLED; no new SQL migration.
- [ ] Controlled end-to-end authenticated note/draft test once sample requests are safely available and user enables private writes.
- [ ] Implement customer account onboarding, private request submission, attachments, and explicit artist/customer quote agreement before opening commissions.

## v1.7 — 2026-11-23: Secure artwork upload and published gallery

- [x] Hostinger SSH confirms PHP GD, WebP support and enough upload capacity for a bounded sequential batch.
- [x] Upload genuine drawings with no per-image fields; MIME/size/dimensions validation, GD re-encoding, EXIF removal and random storage paths.
- [x] Private storage outside webroot, draft-first DB records, authenticated admin previews and publish/unpublish/feature/order management.
- [x] Public DB-backed portfolio feed and WebP response gates; keep demo fallback until published work exists.
- [x] No SQL schema migration, no real images or secrets in public GitHub repository.
- [x] Owner confirmed live upload, publish and unpublish workflows work (2026-11-23).
- [ ] Independently back up the private artwork directory alongside MySQL; verify recoverability.
- [ ] Improve SEO with per-work pages/sitemap and remove public Design Lab at final design launch.

## v1.8 — 2026-11-23: Verified-customer commission intake foundations

- [x] Customer request page using the actual approved catalog, with custom idea option and no assumed prices.
- [x] Customer-owned list and detail APIs; unsent draft quotes and artist-only notes are never returned.
- [x] Transactional text-only request submission, server-stored tier/add-on snapshots, CSRF, HTTPS, strict schema validation and audit.
- [x] Rate limits, per-customer 24-hour cap, and stable HMAC-based idempotency to avoid duplicate retries.
- [x] Explicit server gates for request opening, reviewed terms, published privacy policy and policy-version acknowledgement.
- [x] Hostinger artwork uploader verified by owner (upload/publish/unpublish).
- [ ] Approve Terms/Privacy content, complete SMTP delivery and registration QA, then run controlled customer request tests.
- [ ] Add private reference uploads, secure messaging, quote delivery/acceptance and payments in separate phases.

## v1.9 — 2026-11-23: Customer email service readiness and policy drafts

- [x] Build explicit authenticated TLS SMTP transport using PHP cURL; no silent fallback to PHP mail and no unencrypted SMTP.
- [x] Build fixed GitHub Actions workflow for configuring private SMTP values via pinned Hostinger SSH without exposing the mailbox password.
- [x] Add Artist's Desk email readiness and admin-only test (fixed recipient, CSRF and two-tests/hour rate limit).
- [x] Draft proposed commission Terms and Privacy Policy with owner-specific unanswered items prominently marked; no legal flags enabled.
- [x] Hostinger SMTP configured; SMTP server accepted test with response 250, and separate connected Hostinger-to-Gmail and Gmail-to-Hostinger mailbox tests both arrived in the recipient Inbox (2026-11-23). Original site's individual SMTP-test inbox receipt remains unconfirmed.
- [ ] Owner/legal review of terms, refunds, licence, customer age/retention policies; publish final pages only after approval.
- [ ] Controlled customer signup/verification/password reset testing with no public registrations; then pilot commission requests.


## v1.9.1 — 2026-11-23: Verified mail path and verification resend safeguard

- [x] Confirm a real inbound and outbound delivery between Hostinger `support@drawmeone.com` and a connected technical Gmail mailbox, independent of SMTP acceptance.
- [x] Check public MX, SPF, DKIM selector existence and DMARC policy from Hostinger, without printing private credentials.
- [x] Add dedicated authenticated-service-gated and Turnstile-protected resend workflow for pending customers with strict IP/email limits, fresh one-time token and non-enumerating responses.
- [x] Add customer account UI for resending a lost/expired email verification message without exposing administrative details.
- [ ] Verify the new endpoint's syntax and closed-state safety on the live Hostinger deployment.
- [ ] Controlled signup/verification/resend/password reset and customer isolation tests before enabling public accounts.
- [ ] Publish artist-approved Terms and Privacy; keep order submission and payments closed until requirements are met.


## v2.0 — 2026-11-23: Gated private customer and artist conversations

- [x] Add server-gated plain-text replies for the verified owner of each request and an independent, clearly labeled artist reply action.
- [x] Keep artist-only internal notes and unsent quote drafts invisible to customers; prevent sending to closed/completed requests.
- [x] Require explicit customer messaging flag plus approved policies, verified login, origin/CSRF, rate limiting, bounded message size/history and audit metadata.
- [x] Add responsive request-detail conversation forms with no user text inserted as HTML; both sides show messages only when their server gate allows.
- [x] Fix Apache allowlist for the earlier resend-verification endpoint; verify closed routes return 503 rather than being unexpectedly blocked by rewrite rules.
- [x] Install GitHub Actions source-quality workflow for every PHP/JavaScript change.
- [ ] End-to-end authorized customer+artist messaging, role isolation, CSRF and rate-limit testing with controlled accounts.

## v2.1 — 2026-11-23: Private commission reference images

- [x] Add reversible/gated private customer reference images to request details, with server-side ownership checks and private thumbnail/display image routes.
- [x] Accept only uploaded JPEG/PNG/WebP, limit 4 MB and 16 megapixels per source, strip metadata via GD re-encoding, keep private outside public_html and cap eight files per request.
- [x] Add individual reference removal for owning customer; remove DB visibility first, then delete private WebP variants, with incomplete-cleanup indication.
- [x] Add additive migration 003 and fixed pinned SSH workflow; **Hostinger migration run succeeded**, confirmed new private reference schema and customer service still closed.
- [x] Add live guarded UI in My Requests and artist request review, showing no raw paths or filenames.
- [x] Run source-quality PHP/JS checks; check private storage-key validation and thread closure with no-network tests.
- [ ] Complete end-to-end controlled account upload, removal, access control, malformed image, closure and cross-account tests.
- [ ] Independent backups/restore of private MySQL and private reference/artwork files; implement orphan cleanup and consent/retention policies.
- [ ] Owner-approved Terms and Privacy before activating auth, messaging, reference uploads or commissions.

## Next — v2.2

- [ ] Artist quote delivery with versioned immutable quote, validity expiry, customer acknowledgement and separate payment/contract agreement gate.
- [ ] Customer acceptance/decline and order state transitions only after confirmed owner-approved terms.
- [ ] Verifiable payment providers and manual payment validation with clear refund rules.
- [ ] Mobile/desktop visual polish on real approved artworks, art-specific SEO pages and final public design.


## v2.2 — 2026-11-23: Artist-released quote review and customer decisions

- [x] Add policy-gated artist-only `POST /api/admin/quote-send.php`, releasing only the latest valid unaccepted draft to the verified owner of the corresponding commission request. Review windows are 1–30 days; older pending offers become superseded in one transaction.
- [x] Add secure private quote visibility in `GET /api/request.php`: only released/accepted/declined/superseded quotes and their itemized lines; draft quotes and internal notes remain hidden.
- [x] Add authenticated customer `POST /api/quote-response.php` with quote ownership, current request-policy revision acknowledgement, accepted/declined state, expiry, CSRF and rate limits. Acceptance is audited; quote totals are never supplied by the browser.
- [x] Add artist release and customer review/explicit decision controls with accessible responsive layouts and text-only rendering.
- [x] No order, payment record, automatic email, checkout, automatic paid status or copyright-transfer action occurs in this phase.
- [x] Add quote security smoke tests and PHP/JS CI coverage.
- [ ] End-to-end pilot with real controlled verified customer, expiration, replay/reused decision, CSRF, cross-account access, concurrent acceptance and quote-version tests. Do not enable the feature until these tests and owner-approved policies pass.
- [ ] A separate future commercial implementation must create orders *after* agreed terms and use provider-verified payment status, not a browser success redirect.

**New private switches (all default OFF):** `DMO_QUOTE_DELIVERY_ENABLED`, `DMO_QUOTE_RESPONSES_ENABLED`. Both require existing customer auth and the approved Terms/Privacy/policy revision gates; artist release additionally requires `DMO_REQUEST_ADMIN_WRITES_ENABLED`.

## v2.3 — 2026-11-23/24: Encrypted local backups

- [x] Confirm Hostinger CLI contains mysqldump, tar and PHP libsodium.
- [x] Implement fixed CLI-only private MySQL and artwork/reference archive creation, authenticated XChaCha20-Poly1305 encryption and verification, with zero credentials or private content in Actions logs.
- [x] Generate restricted private backup recovery key on Hostinger; rotate and retain seven newest local snapshots.
- [x] First production encrypted backup successfully created and verified (Actions run 45318772324).
- [x] Enable daily private encrypted backups at 08:17 UTC.
- [ ] Independent encrypted off-site copy and separately preserved recovery key.
- [ ] Full restore drill in an isolated environment; do not restore over production. Current backups are not independently recoverable after a total Hostinger account loss.


## v2.4 — 2026-11-23/24: Published portfolio SEO

- [x] Add server-rendered artwork detail pages with responsive uncropped imagery, genuine alt text, canonical metadata and valid ImageObject schema. Pages only exist for published artist-approved pieces.
- [x] Add generated XML/image sitemap, rewired through the existing /sitemap.xml URL; includes only published works and homepage, never private customer references or draft artworks.
- [x] Add a direct published-artwork page link inside the existing lightbox; keep it hidden for placeholder and demo images.
- [x] Verify dynamic sitemap live (HTTP 200 and XML image namespace), and unpublished detail page stays 404 when there are no published works (Actions run 45319307505).
- [ ] Artist uploads/publishes approved real drawings; browser and Search Console validation for actual artwork pages and image indexing.
- [ ] Decide final public site design and remove Design Lab/dummy artwork after approval; no fake portfolio imagery.


## v2.5 — 2026-11-23/24: Isolated encrypted backup recovery validation

- [x] Add `restore-check` to the existing CLI-only backup tool, decrypting the newest archive into a temporary private directory and validating SQL presence, archive paths/types and WebP file signatures.
- [x] Reject archive entries with unsafe paths, symlink/device types or invalid image headers; clean temporary plaintext recovery files after verification.
- [x] Require the recovery key to already exist when verifying or restoring; only an actual new backup may generate it.
- [x] Verified second encrypted backup and isolated archive recovery on live Hostinger: **10 private image files checked**, production database unchanged, no SQL imported (Actions run 45323755105).
- [x] Make the safe archive recovery check a mandatory part of every daily encrypted backup workflow.
- [ ] Independently copy encrypted backups and recovery key off Hostinger; complete isolated MySQL import + media reattachment to test full disaster recovery.

## v2.6 — 2026-11-23/24: Separate unpaid order confirmation and tracking

- [x] Inspect existing `dmo_orders`, `dmo_payments` and `dmo_deliverables` table structures on live Hostinger with no customer data exposure.
- [x] Add strictly gated authenticated artist-only order confirmation after customer quote acceptance, transaction-safe duplicate protection and audit.
- [x] Record new orders as `awaiting_arrangement` / `unverified`; never create payment rows, mark paid or send payment links.
- [x] Show actual owner-only order status to customers and actual order records in the artist dashboard without exposing payment private notes or other customer data.
- [x] Add order-gate smoke test, PHP/JavaScript lint and live closed-state diagnostics.
- [ ] Run controlled end-to-end quote acceptance → unpaid order creation with two distinct test customers and artist account, checking CSRF, replay, cross-owner reads and duplicates before opening customer accounts.
- [ ] Payment provider verification, controlled order status transitions, deliverables and final approved Terms/Privacy remain separate phases.

## v2.7 — 2026-11-23/24: Live Artist Desk launch readiness

- [x] Extend the existing authenticated admin overview with readiness data that never contains customer identities, secrets, storage paths or payment references.
- [x] Show live email setup, published artwork count, approved policies, auth/commission/message/reference/quote/order gates and local backup freshness.
- [x] Explicitly mark off-site recovery and payment processing as not implemented rather than pretending the site is ready to charge.
- [x] Create a responsive dashboard readiness checklist with manual refresh; no launch-enabling controls added.
- [ ] After approving business policies and performing controlled customer tests, update gated private settings as a separate owner-authorized launch action.

## v2.8 — 2026-11-24: Disposable DB and HTTP integration QA

- [x] Add GitHub Actions MariaDB container that imports committed migrations from scratch and runs tests whenever application/backend tests change.
- [x] Verify fake two-customer order/quote/message ownership, internal-note confidentiality and unpaid order amount using actual SQL.
- [x] Test actual HTTP session/CSRF/origin behavior with anonymous and authenticated synthetic customers.
- [x] Test complete synthetic artist draft → publish quote → customer acceptance → separate unpaid order with rejection of cross-customer actions, browser price override, duplicate acceptance/order, and closed-thread messaging.
- [x] All integration gates passed in Actions run 45418706387; disposable fixtures are reset between HTTP suites and the artist's active-order count is asserted. No Hostinger data or real payments touched.
- [ ] Controlled customer registration/verification/forgot password/Turnstile and image upload QA against a dedicated private pilot environment before launch.

## v2.9 — 2026-11-24: Safe public inquiries and HTTP hardening

- [x] Replace no-contact dead end on homepage and closed commissions page with direct `support@drawmeone.com` general inquiry links. Clarify that email is not an accepted order or payment.
- [x] Add Apache deny rules for internal tests, operational docs and workflows in the deployed repository.
- [x] Keep the closed commission contact panel visible even when JavaScript fails, while signed-in/open states still hide it as appropriate.
- [x] Live read-only SSH diagnostic confirmed the contact link is visible and internal paths are HTTP-denied (run 45417924628).
- [ ] Finalize owner-approved legal/business terms and publish real artwork before public order intake.

## v2.9.1 — 2026-11-24: Accurate artist order counts and isolated CI fixtures

- [x] Fix Artist Desk active-order tally to include `awaiting_arrangement`, `in_progress` and `ready_for_delivery` alongside legacy workflow statuses.
- [x] Expose no payment verification simply because an order is awaiting arrangements; payment counts remain driven only by actual pending payment records.
- [x] Reset **only the disposable localhost CI database** between HTTP suites, so earlier synthetic orders don't contaminate downstream count tests.
- [x] Verify the real authenticated admin overview count over local HTTP with one synthetic unpaid order (Actions run 45418706387).

## v3.0 — 2026-11-24: Verified email and password recovery safety

- [x] Add real HTTP integration tests for account activation, expiry/replay of verification links, session authorization, password-reset expiry/replay, password rehash and multi-device session invalidation using only CI-local MariaDB and synthetic accounts.
- [x] Consume **all** outstanding password-reset tokens after successful reset; block reuse of parallel links.
- [x] Restrict public password-reset confirmation to active, verified customer accounts, not administrator accounts.
- [x] Attempt a password-change notification after committing the reset; delivery failure does not roll back success.
- [x] Confirm full test suite success on GitHub Actions run 45427127474.
- [ ] Controlled live SMTP/Turnstile signup, verification resend, login and reset notification delivery pilot with owner-approved policies.
- [ ] No public account or payment switches enabled.

## v3.1 — 2026-11-24: Disposable WebP/private reference upload tests

- [x] Add PHP GD image-processing dependency in disposable MariaDB CI and a synthetic PNG-only test fixture.
- [x] Exercise actual authenticated multipart upload, CSRF and ownership rejection, private WebP thumbnail/display conversion, and outside-webroot storage.
- [x] Verify owner-only image reads with no-store headers, cross-account and anonymous access denial, owner-only deletion, DB/file cleanup and completed-request write protection.
- [x] Full disposable suite passed (Actions run 45427818462). Customer upload remains disabled on Hostinger.
- [ ] Test real browser reference upload flow and live private disk permissions in a controlled pilot before enabling any public customer reference feature.

## v3.2 — 2026-11-24: Chromium browser QA and direct-contact consistency

- [x] Repair missing general-inquiry contact links in all three alternate site architectures (Atelier, Edition, Signature); all four designs now provide email and commission information without enabling bookings or payments.
- [x] Add Playwright/Chromium browser checks on Ubuntu 24.04 covering all four site layouts at desktop 1440 px, tablet 768 px and mobile 390 px.
- [x] Test local browser gallery rendering and sample attribution, horizontal overflow, script exceptions, layout/mode/logo persistence, lightbox keyboard navigation and Load more.
- [x] Check account, commission and password recovery closed states plus the no-JavaScript general inquiry fallback.
- [x] Browser QA fully passed on GitHub Actions run 45430891562; collect short-retention screenshot artifacts.
- [ ] Safari/iOS QA and controlled authenticated artist/customer browser testing, including actual production upload/download and Turnstile; no feature gates opened.

## v3.3 — 2026-11-24: Artist Desk mobile navigation and guarded browser QA

- [x] Replace the horizontally scrolling admin menu on mobile with an always-visible three-column tab grid (two columns on extra-small screens).
- [x] Add automated Chromium checks of all six Artist Desk tabs at 320/390/768/1440 px, including overview counters, launch readiness, zero horizontal overflow and accessible selected-tab state.
- [x] Confirm that upload/publish, package edit/publish, theme edit/publish and SMTP test actions remain disabled when the private server gates are off; reject all simulated writes.
- [x] Confirm the closed admin workspace stays hidden, all browser checks pass and sanitized screenshots are saved to ephemeral Actions artifacts (run 45434167222).
- [ ] Actual authenticated mobile-device QA with a controlled account before customer launch.

## v3.4 — 2026-11-24: Verified publishing and gallery bulk controls

- [x] Add a full actual-HTTP, disposable MariaDB image lifecycle test covering artist upload, EXIF-stripping WebP conversion, private draft storage and preview, public publishing, featured/order updates, unpublishing, CSRF/origin enforcement and audit.
- [x] Verify public portfolio manifest and direct image API cannot reveal drafts, while public published WebP can load (integration run 45437532651).
- [x] Add filtered All / Private drafts / Published views to the artist artwork library with actual counts.
- [x] Add safe bulk unpublish with explicit confirmation, no image deletion, feature-gated buttons and selection clearing when filters change.
- [x] Expand Chromium admin checks for draft/published counts, filter behavior and cancelled bulk actions.
- [ ] Controlled artist-authenticated upload of genuine rights-cleared artwork on Hostinger; review real images before publishing and verify independent offsite media recovery.

## v3.5 — 2026-11-24: Full encrypted backup recovery verification

- [x] Add disposable source and separate restore MariaDB schemas to the CI workflow, with guardrails against production database access.
- [x] Generate synthetic artist and customer-reference WebP files and verify full CLI backup/encryption, independent decryption, tar extraction, restored SQL import and DB-to-media correspondence.
- [x] First independent full recovery run passed (Actions run 45439549783).
- [x] Require fresh encrypted candidates to pass isolated archive restore-check **before** promotion or pruning previously verified backups.
- [x] Add corrupted-candidate regression: damage a synthetic private image, reject snapshot and preserve last good encrypted backup byte-for-byte (run 45440045053).
- [x] Add fast standalone PHP test lint before costly CI dependency installation.
- [x] Verify v3.5 on live Hostinger: fresh backup passes pre-promotion restore, 3 encrypted snapshots retained and 10 private image files checked (run 45440322221).
- [x] Require read-only SSH diagnostics to validate backup age (<36 hours), restrictive permissions and recovery key presence without printing key contents (run 45440561280).
- [ ] Configure a truly independent off-site encrypted snapshot destination and separately retain the recovery key; no production backup has been copied to a third-party provider.
- [ ] Carry out a controlled production-derived restore on independent infrastructure once destination and access protections are established.

## v3.6 — 2026-11-24: Backup observability and encrypted-only independent transfer

- [x] Make Artist Desk's local-backup readiness require private directory, snapshot, recovery-key permissions and a recent file; do not confuse metadata freshness with authenticated restore.
- [x] Test missing, stale, exposed, symlinked and invalid-key backup fixtures in the Source Quality workflow (run 45444053146).
- [x] Add daily read-only Hostinger health inspections after the scheduled encrypted backup; diagnostics verify the same metadata-only helper.
- [x] Add one deduplicated GitHub issue for failed backup workflow runs, automatically resolved after the latest completed workflow succeeds; ignore out-of-order events. Success-path monitor verified in run 45445471845; no production failure induced.
- [x] Implement and test an authenticated ciphertext-only `export-latest` backup command (full disposable integration run 45444471510).
- [x] Prepare manual-only independent encrypted transfer with rclone remote readback hash validation, rejecting path traversal/plaintext and storing no recovery key (local-simulation run 45444968601).
- [x] Verify latest production backup with 4 retained private encrypted snapshots and 10 image files inspected (run 45445427769).
- [x] Track independent destination and separate recovery-key custody as [GitHub issue #1](https://github.com/pixel375/drawmeone/issues/1).
- [ ] Select and approve independent storage, configure private rclone credentials/destination, verify a real encrypted transfer and separately held key, then enable recurring off-site transfer and full independent restore drills.

## v3.7 — 2026-11-24: Customer request history and real-browser test coverage

- [x] Add signed-in private request search by reference, drawing category/package and status, with All / Active / Completed or closed filters and clear counts.
- [x] Replace raw request-status values with understandable customer-facing labels on history cards and request details; do not persist private query or briefs in browser storage.
- [x] Guard concurrent history refreshes so late stale responses cannot replace current results.
- [x] Add synthetic customer Chromium coverage for closed-intake existing history, quote/order display and disabled private messaging/uploads; plus open-intake form compatibility and idempotency on an intercepted test API.
- [ ] Controlled real customer browser/SMTP/Turnstile pilot before opening registration or any live commission intake.

## v3.8 — 2026-11-24: Clearer quote decisions and race-safe private detail

- [x] Disable quote acceptance until the customer explicitly acknowledges the offered scope/price; leave decline independently available.
- [x] Restore eligible quote action buttons after failed/expired server responses; suppress outdated response navigation.
- [x] Show human-readable quote states (sent, accepted, declined, superseded, expired) and block expired or historical decisions in the UI.
- [x] Add request-detail Refresh, accessible loading/error states and hide potentially stale private content until the latest fetch succeeds.
- [x] Guard against out-of-order detail responses and close/refresh races with a monotonically increasing request sequence.
- [x] Extend signed-in synthetic Chromium tests for expired/replaced quotes, failed and successful quote decisions (without new order/payment), stale response isolation and detail refresh (full browser run 45462290911).
- [x] Read-only Hostinger verification confirmed v25 quote/detail assets are deployed while customer registration and commission intake stay closed (run 45462408843).
- [ ] Controlled production customer/artist pilot once policies, genuine artwork and access are approved; do not open payments on browser QA alone.

## v3.9 — 2026-11-24: Validated customer onboarding, verification and recovery UX

- [x] Require password confirmation on signup and block mismatched passwords before POST; enforce maximum UTF-8 password length.
- [x] Improve sign-in and recovery messages for rate limiting, expired links, and transient errors; avoid telling a successful login that its password was incorrect if `me.php` fails.
- [x] After reset success, offer sign-in; after expired links, offer a new reset link; allow retries after temporary errors without prematurely clearing passwords.
- [x] Restrict public email verification to pending, unverified customer roles and consume all other outstanding verification links after success.
- [x] Add disposable database tests for pending-admin verification rejection and parallel-link invalidation (full integration run 45465632043).
- [x] Add Chromium tests for closed auth gates, synthetically enabled signup, resend verification, login/logout, verification links, reset-request and password-reset errors/success at narrow and wider screen widths (browser run 45465658442).
- [x] Read-only Hostinger verification confirmed account signup/recovery v15/v13 assets, PHP syntax and closed customer gates (run 45465970997).
- [ ] Controlled real account/SMTP/Turnstile pilot only with approved policies and safeguards. Production customer registration, new commission intake and payments stay disabled.

## v4.0 — 2026-11-24: Automated accessibility and keyboard navigation

- [x] Add axe-core WCAG 2.0/2.1 A/AA checks to Chromium CI for all four homepage designs, light/dark desktop, mobile, and closed customer pages; retain structured violations report.
- [x] Find and fix serious low-contrast explanatory labels in desktop design/logo selector by removing opacity and increasing contrast; version `styles.css?v=11`.
- [x] Add keyboard-visible Skip to main content links to customer account and password-reset pages, verified with real Chromium keyboard navigation.
- [x] Confirm comprehensive initial dark/light accessibility/skip-link test run passes (run 45468970978).
- [x] Add open gallery lightbox axe audit and keyboard focus restoration test; full v4.0 Chromium QA passed (run 45469194540).
- [x] Read-only live Hostinger verification confirms stylesheet v11 and both keyboard skip links deployed, with registration/intake closed (run 45469404677).
- [ ] Manual screen-reader testing (VoiceOver/TalkBack), 200%+ zoom and real authenticated workflows before customer launch; automated axe success does not certify WCAG compliance.
