# Draw Me One — Product Roadmap
Updated: November 2026
Status: design direction / foundational implementation

## Product vision
Draw Me One is an artist-led website that converts admiration for original drawings into clear, trusted, paid custom commission requests. The original artwork is central; the user experience must feel distinctive, fast, accessible, and personal.

## Confirmed requirements (from project owner)
- Domain: https://drawmeone.com
- Hosting: Hostinger shared hosting, deployed from GitHub `pixel375/drawmeone` (main).
- Gallery must contain **only drawings created by the artist**.
- **No text, descriptions, watermarks, or other content placed on the portfolio images**. Artistic examples have no visual overlays.
- Artist must eventually be able to upload/manage art without GitHub access through an authenticated dashboard and database.
- Site sells custom drawing commissions, not generic print-on-demand by default.
- Visitors need a way to submit commission requests and pay; exact provider/checkout flow undecided.
- Contact methods to evaluate include email, Discord and alternatives.
- Strong visual polish, differentiated brand, mobile accessibility, excellent search discoverability.

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
- [ ] Receive and publish at least 6-12 **approved real drawings** in optimized variants.
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
- [ ] Admin actions: drag-drop multiple uploads, descriptive alt metadata, reorder, categorize, draft/publish, edit/remove, audit actions.
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
- Artist/studio display name and desired palette/style, brand assets.
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
