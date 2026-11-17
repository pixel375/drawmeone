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
- [ ] Admin: hashed password credentials, MFA if compatible, HTTPS, secure sessions, CSRF protection, rate limits, login alerts, backup/recovery.
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
- [x] Customer login deliberately denies admin/staff roles pending MFA-capable admin system.
- [x] Add live account and recovery pages that stay closed until security/SMTP/mail integration is configured and enabled.
- [x] Implement disabled-by-default private switches DMO_AUTH_ENABLED and DMO_REGISTRATION_ENABLED; no real writes on deployment.
- [ ] Import migration 002 into Hostinger MySQL (via phpMyAdmin) and verify.
- [ ] Configure and test Turnstile, real sending email, secure HTTPS detection and end-to-end auth flows in a controlled test account.
- [ ] Implement independent admin MFA/roles and audit verification, plus private commission request endpoints.
