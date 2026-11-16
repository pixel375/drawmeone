# Draw Me One — Commission & Admin Product Specification (Draft)

Updated 2026-11-16. This documents requirements supplied by the project owner and the implementation decisions still pending. **It is not approval to open commissions, charge real customers, or publish client information.**

## 1. Positioning, subject matter, content boundaries

- One independent artist creating individual hand-drawn original and digital illustrations: anime-style characters, original characters (OCs), game-character commissions, sketches, coloured portraits, etc. Artwork is **not generated in bulk or by AI**.
- Drawings supplied by the project owner as visual references on 2026-11-16 are **REFERENCE ONLY**; they are not authorized for website upload. Do not copy them into GitHub, public hosting, designs, or marketing assets without explicit publication permission.
- The public site is primarily a **personal art portfolio and commission-request page**, not a corporate agency landing page, digital image factory or image stock catalog.
- Studio is the only currently acceptable front-end structural design. Three other variants are exploratory/unapproved; stop polishing them without a fresh design direction. Proposed later explorations: intimate Midnight Sketchbook or Artist's Desk, rather than oversized, dense, agency-like templates.
- Public visitors may change **only light/dark mode**. Admin chooses published site design, logo, colours, default colour mode (probably dark), gallery layout and all service configuration. Development-only Design Lab remains available for visual exploration, but must not be public for ordinary customers at launch.

## 2. Proposed commission packages (illustrative, NOT finalized prices)

Use data-driven service tiers. All tier names, descriptions, ranges, features, order, visible samples, active flags, max quantities and add-ons are editable by admin. Currency and final approved pricing **TBD**; the dollar figures below reflect initial rough owner examples only.

| Package | Illustrative estimated range | Initial feature ideas |
| --- | --- | --- |
| Simple | $5–$10 | Quick sketch or simplified character drawing; lower detail; scope and revisions TBD |
| Normal | $15–$20 | More detail and colouring, basic revision, individual character portrait/illustration |
| Advanced | $25–$30 | Additional detail, optional/defined background, drawing-process timelapse, multiple revisions |

Admin-configurable add-ons may include priority rush, additional character, complex background, extra revision, extra file format, alternate pose, extra process video. Availability, feasibility, pricing and deadlines need artist approval. Some additions are incompatible with certain tiers; the model should support eligibility rules.

**Client-visible numbers are estimates, not a promise or a binding quote.** Submission remains free; artist supplies an exact price and deliverables after reviewing each brief.

## 3. Request experience / account creation

Mobile-first funnel:
1. Visitor sees original artwork, service overview and prominent **Commission a Drawing** CTA (not yet active).
2. Choose a package (Simple/Normal/Advanced) and optional add-ons. The UI displays an informative estimate / indicative range and a clear **Final quote confirmed by artist** disclaimer. Do not show fabricated example images.
3. Enter written brief and choose subject type (OC, fandom/game/anime, other); add optional private reference images. Optional requested deadline, dimensions/aspect ratio, preferred delivery format, notes, preferred contact method.
4. Review selections and estimated range, commission terms, and privacy/portfolio permission. Consent to necessary data processing/terms separate from optional marketing or portfolio publication.
5. During quote submission, new customer creates a minimal account: display name, email and password; existing customer signs in. Verify email (account can be provisionally pending). Do not force account creation before exploring the gallery or estimate. The customer sees a confirmation and trackable request ID.
6. The request enters **Pending Review**, without taking payment. Artist can ask clarifying questions through the onsite conversation.
7. Artist sends exact quote, agreed scope, revision count, turnaround terms, payment instructions. Customer approves or declines within the validity period.
8. After approval, collect payment through chosen external/manual channel; admin verifies the payment through authoritative provider/bank evidence and changes the order status. Never mark paid from a screenshot or browser return alone.
9. Artist works, sends progress updates/optional previews, completes agreed revisions, and delivers digital files privately.
10. Customer can review message thread, download deliverables, and confirm completion.

## 4. Account, messaging and permissions

Customer area:
- Registration, login, email verification, password reset; hashed password; persistent sessions; account deletion/export request where applicable.
- Dashboard: requests/orders, status/history, quotes, payment instructions, secure messages, private references and final downloads.
- Message threads linked to each order, plus possibly general support conversations; unread indicators and email notifications (without leaking private reference URLs).
- Methods: **onsite messages** primary for order records; **email** for notifications and optional responses; **Discord** as a clearly labeled external DM/contact link. Discord DMs should not be represented as automatically synchronized unless that integration is actually built.
- Reference uploads are private, authenticated and permission-checked; public finished-work publishing must always be opt-in at individual-order level.
- Suggested optional checkbox text: **“You may display my completed drawing in your public portfolio and social posts.”** Recommended default **unchecked** (project owner originally suggested prechecked; confirm before public launch). Store current choice, who consented, when, and the exact wording/version. Treat withdrawal requests explicitly and prevent publication without valid consent. An agreed copyright assignment does not silently grant portfolio rights.

## 5. Ownership, third-party characters, commissioning terms

Owner preference: buyers of completed custom drawings should receive full ownership and broad use rights. Draft contract terms should distinguish:
- **Original commission:** written and appropriately signed assignment of the artist's transferable copyright in her own original contribution, effective once payment is fulfilled, with clearly specified files and scope. Under Canadian copyright law, assignment must be in writing signed by copyright owner; moral rights require separate explicit treatment/waiver.
- **Existing game/anime/other IP:** the artist cannot assign or authorize third-party underlying character rights she does not own. Fan-art permissions and commercial-usage restrictions may apply; these need clear screening and appropriate limitations.
- Describe whether artist may retain a private archival copy. Separate publicity/display permission from commercial-use rights.
- Terms should cover scope, client-supplied references, changes, price/payment timing, cancellation, refunds, chargebacks, revision count, delivery timetable, source files, timelapses and any limitations. Legal review before accepting paid orders.

Official starting points:
- https://laws-lois.justice.gc.ca/eng/acts/C-42/FullText.html
- https://ised-isde.canada.ca/site/canadian-intellectual-property-office/en/what-you-should-know-about-copyright

## 6. Payment options — phase 1 manual, phase 2 integration

1. **PayPal personal account** can receive legitimate sales payments, including Goods & Services, but PayPal recommends a Business account if activity is primarily selling. Do not ask customers to use Friends & Family for a commercial commission. Prefer PayPal request/approved payment instructions to start; account visibility/privacy and provider rules must be checked.
2. **Interac e-Transfer** as a manually verified Canadian-bank-account option. Standard e-Transfer is not a general checkout method for US-based customers without a Canadian bank account. Request reference/order number in the transfer memo if supported by institution.
3. **Buy Me a Coffee** link for optional support/tipping (distinct from commissions). If considered for exact quote payment later, verify shop/payout capabilities and manually reconcile payments first; it carries platform and processing fees. Canada supported for creator payouts.
4. Potential future alternatives: provider-hosted payment links/invoices through a verified merchant processor. No card number handling on our own site.
5. Store payment method, provider reference, currency, expected/exact amount, payment state, timestamps and any provider reconciliation proof. For bank/Interac, the artist/admin manually confirms with bank history; never trust a screenshot.

## 7. Status workflow / notifications

Recommended separate **quote/request**, **order**, **payment** and **delivery** states, rather than one overloaded string:

- Request: draft / submitted / clarification_needed / quoted / declined / expired / accepted
- Order: awaiting_payment / confirmed / queued / drawing / awaiting_feedback / revision / ready_to_deliver / completed / cancelled / disputed
- Payment: unpaid / pending_verification / paid / partial / refunded / disputed
- Delivery: pending / delivered / accepted; multiple versions/files allowed

Every transition should record a timestamp, actor, internal reason and applicable notification. Do not expose internal notes to the customer. Artist can manage availability/queue and temporarily pause commission requests.

## 8. Admin dashboard — priority-based information architecture

**Tier 1: Work first (opens by default)**
- Dashboard: NEW requests, orders requiring action, overdue replies, unread threads, pending quote approvals, payment verifications, current workload and due dates.
- Orders & Requests: filters, custom quote builder, approve/decline, message conversation, attachments, revision history, delivery upload, payment confirmation and consent status.
- Messages: unified inbox, per-order conversation, unread count, email alert settings; separate internal notes.

**Tier 2: Artwork**
- Bulk drag-and-drop 1–100+ images with **zero required per-image title/description/alt field**; auto-safe filename, media info, EXIF removal, thumbnail/derivative generation, duplicate detection, progress, retry and publish/hide all.
- Reordering, pin featured drawings, archive/delete, optional categories and optional manually revised alt text.
- Genuine artwork only; do not ingest the attached reference collages without permission. Private client references stored separately from published artwork.

**Tier 3: Website & administration**
- Design: select Studio/future approved layout; pick logo from the existing set; colour theme and tokens; default dark/light; publicly allow only light/dark toggle; previews/drafts; publish/revert to previous published state.
- Commission packages: unlimited editable tiers, pricing ranges, features and add-ons; sample media per tier; currencies, service descriptions, capacity and availability.
- Website content: copy, About artist, FAQ, process descriptions, CTA text, social links, supported contact methods.
- SEO: titles/descriptions, social preview, canonical settings, crawlability/sitemap, artwork alt text when desired, indexing status.
- Security/CAPTCHA: Cloudflare Turnstile site key, admin-only secret key in protected server configuration, action-specific enforcement, rate limits and audit events. Never show secrets in public output.
- Customers: user list, roles, account state, messages/requests and approved privacy controls; avoid exposing password hashes or sensitive data.
- System: email delivery, payment methods, backups, logs, uploads limits, notifications, user/session security and health checks.

## 9. Security and hosting requirements

Target initial stack: Hostinger shared-hosting-compatible PHP 8.x + MySQL/MariaDB, PDO, server-rendered public gallery, no mandatory persistent Node backend.

- Email/password accounts: use `password_hash` with Argon2id if supported or bcrypt; email verification, password-reset tokens, secure HttpOnly/SameSite cookies, session regeneration, CSRF defense and login throttling.
- Admin: MFA strongly recommended (passkeys/TOTP if supportable), strict role-based access, audit trail and backup restoration testing.
- **Turnstile on signup, login and quote submission** with backend Siteverify token validation, plus rate limits, bot/abuse detection and generic errors. CAPTCHA alone is insufficient.
- Quote/private reference uploads: enforce actual MIME sniffing, size/pixel limits, safe random names, metadata stripping, safe image transcoding, quarantine/anti-malware as feasible, access controlled by account/order; never public GitHub or public media URL.
- Public portfolio images stored separately with optimized WebP/AVIF/JPEG derivatives, immutable cacheable names and safe media policies.
- Secure configuration outside the web root; no API secrets or personal client data committed to GitHub.
- SQL migrations, rollback, automated encrypted backups and data retention/deletion policy.
- Cloudflare Turnstile Free can work without proxying the site through Cloudflare, but server-side Siteverify is mandatory: https://developers.cloudflare.com/turnstile/get-started/

## 10. Suggested initial database entities (not final schema)

`users`, `email_verifications`, `password_resets`, `sessions`, `roles`, `customer_profiles`,
`commission_packages`, `package_features`, `addons`, `addon_package_rules`, `commission_requests`,
`request_addons`, `quotes`, `quote_items`, `orders`, `order_status_events`, `payments`,
`conversations`, `messages`, `attachments`, `deliverables`, `showcase_permissions`,
`portfolio_artworks`, `artwork_media`, `site_settings_draft`, `site_settings_published`,
`notification_events`, `audit_log`, `system_jobs`.

Avoid premature schema complexity: ensure only actually required tables enter the first migration.

## 11. Acceptance conditions before enabling real submissions/payments

- Artist approves package copy, actual currency and prices, deliverables and terms.
- Real portfolio photographs/images approved for publication, not pulled from owner reference-only attachments.
- Verified customer identity and account recovery, working Turnstile, rate limiting, secure private file storage.
- Admin inbox can quote/reply/decline, quote approval is recorded, no charge before accepted exact quote.
- Manual payment reconciliation documented and test-driven, fake bank/PayPal screenshots do not change payment state.
- Buyer rights and portfolio consent documented; opt-in cannot be forced, permission records auditable.
- Phone + desktop end-to-end QA, no cropped drawings in lightbox; backups and basic uptime monitoring confirmed.
- Published design choices are admin-only; ordinary visitor sees only light/dark toggle.

## 12. Questions that can be decided later

- Actual currency (CAD vs USD), whether prices differ by location and whether taxes apply.
- Revisions per tier, rush availability, max commission concurrency, precise delivery timelines.
- Whether regular commissions of third-party characters are accepted and under what allowed uses.
- Whether email replies sync into onsite messages or email sends notifications only.
- Deposit/full-payment policy, exact payout rails and whether PayPal business conversion eventually makes sense.
- Specific copyright assignment clause, portfolio-publication checkbox default, and moral-rights treatment; obtain appropriate legal review.
