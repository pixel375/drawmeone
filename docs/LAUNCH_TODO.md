# DrawMeOne — Canonical launch TODO

Created 2026-11-26 (Session XXVI, pass 01). Baseline `6554d07`. **This is the live source of truth for all future ChatGPT/Codex/other development sessions.** It supersedes older roadmap checkboxes for remaining work; keep the historical roadmap and milestone docs as evidence. Launch is NOT authorized.

## Mandatory instructions for every future session / pass

1. Read this file, its latest pass log, recent main commits and actual GitHub Actions results before working. Resume unfinished AUTO items in priority order; fix regressions first. Use substantial multi-task passes where practical; continue on main per owner workflow, with fresh file SHAs and safe non-destructive changes.
2. **Skip OWNER and AFTER items now**. Do not ask the owner for manual input or block autonomous work on these. Do all feasible AUTO work (including preparation for blocked work) before a consolidated owner handoff. AFTER means blocked on an explicit owner prerequisite, approved external action or production pilot. OPTIONAL is excluded from the launch-critical path unless the owner elects it. Split mixed items if needed. No commercial gates, legal approvals, live payment, artwork publication, indexing, deletion or secret transfer without explicit authorization. No invented test data in production.
3. Keep stable IDs. Add, split, clarify or reprioritize tasks as evidence warrants; never silently erase an unfinished requirement. Mark superseded items with replacement IDs/reason. Treat owner visual-pass feedback as a new batch after autonomous completion.
4. **At the end of EVERY pass, update this file in GitHub**: check off each completed item only when its acceptance criteria are verified, append a short inline evidence note next to each newly checked item (commit/workflow IDs, test result or approved owner decision), and append a dated pass log with IDs attempted/completed, changes, results, remaining issues, skipped prerequisites and next task(s). If CI is pending, leave the item unchecked and record commit + run IDs; the next pass must reconcile them. A green synthetic test is not a real production pilot. A documentation-only commit does not imply browser verification.
5. Keep this checklist current even if interrupted: record partial work as pending, failed checks and exact next steps. Never mark a task complete merely because code was committed, a report generated or a gate configured. Never expose private records, credentials, backup keys or secrets in notes. Follow the existing [controlled launch runbook](CONTROLLED_LAUNCH_RUNBOOK.md) and [offsite recovery issue #1](https://github.com/pixel375/drawmeone/issues/1). Retain commercial gates closed until explicit staged approval.
6. When all actionable AUTO items are checked (or accurately classified with reasons), stop feature expansion, report the remaining owner actions together and hand off for the owner's manual visual pass. After owner feedback, update the checklist and continue.

**Legend:** `[AUTO]` = ChatGPT can work independently now; `[OWNER — MANUAL]` = owner/artist action or decision, defer; `[AFTER — BLOCKED]` = requires listed approval/prerequisite, defer; `[OPTIONAL]` = not required. IDs are stable. Completion = checked box plus evidence note. Dates/commit references are evidence, not approval.

## Baseline evidence

- Main `6554d07`: source [45765519122](https://github.com/pixel375/drawmeone/actions/runs/45765519122), database [45765519213](https://github.com/pixel375/drawmeone/actions/runs/45765519213), Hostinger [45765519146](https://github.com/pixel375/drawmeone/actions/runs/45765519146) passed. Firefox/WebKit [45765478127](https://github.com/pixel375/drawmeone/actions/runs/45765478127) passed on `679006d`; Chromium [45765478164](https://github.com/pixel375/drawmeone/actions/runs/45765478164) failed at privacy history assertion line 307. No latest full green suite yet. Production: zero published art; admin/upload enabled; SMTP configured; registration and quote/order gates closed; no policy pin; clean integrity scan. Independent offsite recovery not configured.
- Core lifecycle features are already implemented; AUTO items generally mean audit, close gaps and verify rather than rebuild. Production tests remain separately gated. See [roadmap](ROADMAP.md), [legal draft](CUSTOMER_POLICIES_DRAFT.md), [recovery](INDEPENDENT_RECOVERY_MILESTONE.md), [hosted invoice](HOSTED_CHECKOUT_MILESTONE.md).

## Remaining checklist

### Regressions and release verification

- [ ] **REG-01** [AUTO] Resolve Chromium privacy-history regression: distinguish failed older page from failed current refresh; preserve drafts and fail closed.
- [ ] **REG-02** [AUTO] Extend privacy regression coverage: explicit retry, cursor/deduplication, status gating, UTF-8 and uncertain draft preservation.
- [ ] **REG-03** [AUTO] Audit recent CI failures, fix actual regressions; rerun relevant source, DB, Chromium, Firefox/WebKit and live deployment checks.
- [ ] **REG-04** [AUTO] Verify exact release and workflow evidence; resolve additional defects. Final full release verification repeats after changes (see OPEN).

### Authentic portfolio

- [ ] **ART-01** [OWNER — MANUAL] Supply/approve original artist drawings and publication rights; do not use reference collages or fabricated artwork.
- [ ] **ART-02** [OWNER — MANUAL] Upload/publish initial collection (suggested 6–12+), select featured art, arrangement and watermark preferences.
- [ ] **ART-03** [AUTO] Verify bulk zero-required-metadata upload, draft/publish, retries, ordering, optional metadata, image orientation and complete-image viewer with synthetic fixtures.
- [ ] **ART-04** [AUTO] Verify public mode never displays demos, private drafts or legacy/unapproved artwork, including failures.
- [ ] **ART-05** [AFTER — BLOCKED] Review actual published art, portrait/landscape rendering, thumbnails, full-view and ordering with artist on real devices. Depends ART-01/02.

### Public appearance and content

- [ ] **DES-01** [AUTO] Audit and refine Studio-first desktop/mobile layout, responsive navigation, typography, spacing, dark/light and gallery usability without publishing settings.
- [ ] **DES-02** [AUTO] Verify public mode hides Design Lab and alternative experiment controls; retain protected artist appearance options.
- [ ] **DES-03** [AUTO] Audit obsolete previews/status endpoints; restrict or retire unsafe public development surfaces while preserving documented internal tooling.
- [ ] **DES-04** [AUTO] Prepare editable homepage introduction, headings, CTAs, brand and contact presentation where missing; avoid invented artist claims.
- [ ] **DES-05** [AUTO] Verify branding assets, favicon, social preview fallback and theme consistency across pages.
- [ ] **DES-06** [OWNER — MANUAL] Choose final layout, palette, logo, default mode/gallery (Scroll preferred historically), approve homepage copy and actual social image.
- [ ] **DES-07** [AFTER — BLOCKED] Apply approved appearance and copy, publish settings and verify production presentation. Depends DES-06, ART-01.
- [ ] **DES-08** [OWNER — MANUAL] Perform manual visual pass only after autonomous backlog is exhausted; supply changes/features list.
- [ ] **DES-09** [AFTER — BLOCKED] Implement owner visual-pass feedback and reverify. Depends DES-08.

### Packages and operating rules

- [ ] **CAT-01** [AUTO] Audit package/extra editor, compatibility, currency, publication validation, search/filter/draft safeguards.
- [ ] **CAT-02** [AUTO] Verify public estimates, FAQ, custom requests, clear included/excluded extras and capacity/pause UI.
- [ ] **CAT-03** [AUTO] Prepare artist-facing setup guidance, validate configuration and prevent accidental publication or conflicting settings.
- [ ] **CAT-04** [OWNER — MANUAL] Choose actual styles/categories, tiers, starting prices/currency, extras, included work, revision allowances/prices.
- [ ] **CAT-05** [OWNER — MANUAL] Choose turnaround, delivery formats, accepted subject matter, capacity and pause rules.
- [ ] **CAT-06** [AFTER — BLOCKED] Enter/approve/publish actual packages and intake settings; verify real estimates. Depends CAT-04/05, LEG approvals.

### Legal policies and business details

- [ ] **LEG-01** [AUTO] Audit policy bundle publication, historical digest/version pins, agreement continuity, printable policies and safe draft handling.
- [ ] **LEG-02** [AUTO] Prepare owner decision worksheet and draft policy sections with explicit blanks and scope consistent with implemented features; no legal approval assumed.
- [ ] **LEG-03** [OWNER — MANUAL] Confirm legal operator, jurisdiction, support/privacy contact, applicable consumer/tax/accounting and privacy obligations; obtain appropriate review.
- [ ] **LEG-04** [OWNER — MANUAL] Decide acceptable content/fan art, age/parental consent, copyright, personal/commercial license and portfolio permission.
- [ ] **LEG-05** [OWNER — MANUAL] Approve revision, deposit/payment timing, cancellations/refunds/disputes, privacy/retention and actual service provider disclosures.
- [ ] **LEG-06** [OWNER — MANUAL] Approve final Terms and Privacy text and authorization to publish.
- [ ] **LEG-07** [AFTER — BLOCKED] Publish reviewed immutable policy bundle, pin digest and revision and verify historical/current access; keep activation separately authorized. Depends LEG-03–06.

### Independent recovery (issue #1)

- [ ] **BKP-01** [AUTO] Review local encrypted backup freshness, schedule, monitoring and candidate restore/rotation checks; verify actual latest workflow evidence.
- [ ] **BKP-02** [AUTO] Review ciphertext-only rclone export, remote readback, path safety, least-privilege and independent failure monitoring readiness using local simulation.
- [ ] **BKP-03** [AUTO] Improve isolated restore guidance and synthetic restore coverage for DB, artwork, references, deliveries, policy pins and app-key-encrypted invoices.
- [ ] **BKP-04** [OWNER — MANUAL] Choose/authorize independent storage provider and private destination; establish account/least-privilege credentials.
- [ ] **BKP-05** [OWNER — MANUAL] Arrange separate secure custody of backup key AND original DMO_APP_KEY, private configuration and policy/release inventory; never paste secrets in chat/issues.
- [ ] **BKP-06** [AFTER — BLOCKED] Configure private offsite credentials and destination through approved secure mechanism; transfer real ciphertext and verify remote readback. Depends BKP-04/05.
- [ ] **BKP-07** [AFTER — BLOCKED] Perform isolated independent production-derived restore with separate keys; document aggregate verification only. Depends BKP-06.
- [ ] **BKP-08** [AFTER — BLOCKED] Enable recurring offsite transfer, retention lifecycle and independent failure monitoring after pilot; close issue #1 only on evidence. Depends BKP-07.

### Account onboarding

- [ ] **ACC-01** [AUTO] Exercise synthetic signup, verification/resend, login/logout, expiry, recovery, password change and other-device revocation.
- [ ] **ACC-02** [AUTO] Review real SMTP/Turnstile configuration and read-only diagnostics; test mail formatting, rate limiting and error UX without sending unsolicited mail.
- [ ] **ACC-03** [AUTO] Audit role/session isolation, auth endpoints, customer/admin permissions and private history behavior.
- [ ] **ACC-04** [OWNER — MANUAL] Authorize bounded production pilot, test identities and receiving inbox access; confirm approved policies.
- [ ] **ACC-05** [AFTER — BLOCKED] Test real production registration, CAPTCHA, verification/resend, common email providers, login/logout, reset and sessions; record results with no PII. Depends ACC-04, LEG-07.
- [ ] **ACC-06** [AFTER — BLOCKED] Test controlled production role/cross-account isolation and mobile onboarding; close temporary pilot gates. Depends ACC-05.

### Commission intake and quotes

- [ ] **REQ-01** [AUTO] Exercise synthetic end-to-end request/duplicate retry, private reference upload/remove/read denial, messaging, notifications and artist review.
- [ ] **REQ-02** [AUTO] Exercise quote draft/version/send, expiry/decline/accept, historical terms, separate unpaid order, withdrawal/cancellation and pause/capacity.
- [ ] **REQ-03** [AUTO] Audit stale responses, retry safety, concurrency, cross-account isolation and error recovery; improve tests where missing.
- [ ] **REQ-04** [AFTER — BLOCKED] Pilot bounded production intake at capacity one with two controlled accounts: request, retries, references, messaging, quote/decision, history, unpaid order and cancellation. Depends ACC-05/06, CAT-06, LEG-07, owner pilot authorization.
- [ ] **REQ-05** [AFTER — BLOCKED] Verify privacy/role boundaries and close temporary gates after pilot; retain evidence. Depends REQ-04.

### Payment provider and financial operations

- [ ] **PAY-01** [AUTO] Audit provider-neutral invoice link validation, encryption, owner-only redirect, exact amount/currency, expiration, replacement, closure and duplicate prevention.
- [ ] **PAY-02** [AUTO] Test synthetic reconciliation, failure/duplicate/expired payment evidence, holds/restoration, full external refunds and download restrictions.
- [ ] **PAY-03** [AUTO] Document provider selection criteria and manual reconciliation instructions, account/amount/currency verification and exception workflow.
- [ ] **PAY-04** [OWNER — MANUAL] Select provider/merchant account, complete verification, choose currencies/fees/countries and manual vs automatic payments.
- [ ] **PAY-05** [OWNER — MANUAL] Approve payment timing/deposits, refund/partial refund rules and any live test transaction.
- [ ] **PAY-06** [AFTER — BLOCKED] Validate actual provider fixed-total single-use invoice URL format and account, success/failure/expiry/closure/duplicate behavior in sandbox if available. Depends PAY-04/05.
- [ ] **PAY-07** [AFTER — BLOCKED] Implement provider-specific API/webhooks only if selected: signed/authenticated event validation, replay/idempotency, merchant binding, amount/currency/status, retries/disputes/audit. Depends PAY-04.
- [ ] **PAY-08** [AFTER — BLOCKED] Verify actual settlement, external closure, refund/dispute and hold procedures; approve payment activation separately. Depends PAY-06 and PAY-07 if automatic.

### Production and delivery

- [ ] **FUL-01** [AUTO] Exercise synthetic paid-order production stages, deadlines, proof release, customer approval, included revisions, final upload/release/download and history.
- [ ] **FUL-02** [AUTO] Audit file quality/format limits, private downloads, cross-account denial, financial hold restrictions, retry/stale guards and notifications.
- [ ] **FUL-03** [AFTER — BLOCKED] Complete controlled real paid-order pilot with authorized test arrangements and two accounts; test proof, revision, final delivery, notifications, holds and completion. Depends PAY-08, REQ-04.
- [ ] **FUL-04** [OWNER — MANUAL] Approve actual delivery file formats/quality and artistic proof workflow.

### Privacy and retention

- [ ] **PRV-01** [AUTO] Audit customer export and privacy case lifecycle, scoped data, request withdrawal, artist review and uncertain submission handling.
- [ ] **PRV-02** [AUTO] Audit review-only retention inventory, legal holds, backup expiry and aging cases; prepare safe operational guidance.
- [ ] **PRV-03** [AUTO] Prepare bounded non-destructive dry-run cleanup design/tests, separating auth records, private media and financial/agreement records. No production deletion.
- [ ] **PRV-04** [OWNER — MANUAL] Approve per-record retention, deletion/correction, response procedure, legal holds, backup expiry and legal/accounting requirements.
- [ ] **PRV-05** [AFTER — BLOCKED] Implement/enable only approved narrowly scoped cleanup, with dry runs, audit, dependencies and independent recovery; never purge production without explicit authorization. Depends PRV-04, BKP-07.
- [ ] **PRV-06** [AFTER — BLOCKED] Test real customer export, privacy requests and manual response process with authorized test identities. Depends ACC-05, LEG-07.

### Security and operations

- [ ] **SEC-01** [AUTO] Review private configuration contradictions and release prerequisites via read-only checks.
- [ ] **SEC-02** [AUTO] Audit HTTPS, session cookies, CSRF, role/ownership checks, direct file access, private config/backups/upload exposure and Apache allowlist parity.
- [ ] **SEC-03** [AUTO] Review hostile uploads, MIME/size/dimension validation, private storage, concurrent quotes/payment/delivery and integrity monitoring.
- [ ] **SEC-04** [AUTO] Review admin recovery, login alerts, session controls (no MFA per original requirement) and safe security logging.
- [ ] **SEC-05** [AUTO] Check Hostinger resource/disk limits, PHP upload settings, health and backup monitoring; document any access limitations.
- [ ] **SEC-06** [AUTO] Prepare incident response, maintenance, rollback and gate dependencies; preserve historical data and application key.
- [ ] **SEC-07** [AFTER — BLOCKED] Perform authorized production security and operational pilot checks without real customer data or destructive probes. Depends ACC-04 and pilot stages.

### Accessibility, performance and device testing

- [ ] **QA-01** [AUTO] Audit automated cross-browser tests across Chrome/Firefox/WebKit and synthetic authenticated desktop/mobile layouts.
- [ ] **QA-02** [AUTO] Review axe reports, keyboard focus, reflow, contrast, touch targets, error announcements and zoom approximations.
- [ ] **QA-03** [AUTO] Measure representative synthetic gallery load, caching, pagination, lazy loading and image sizes; improve avoidable bottlenecks.
- [ ] **QA-04** [OWNER — MANUAL] Perform actual iPhone/Android and Safari/Edge/Chrome/Firefox device visual/interaction review; manual screen reader (VoiceOver/TalkBack) and actual 200%+ zoom, or authorize suitable tester.
- [ ] **QA-05** [AFTER — BLOCKED] Run actual-art performance/PageSpeed and final live device/accessibility QA; implement findings. Depends ART-02, DES-08, QA-04.

### Search discovery

- [ ] **SEO-01** [AUTO] Audit titles/descriptions, canonical URLs, structured data, artwork pages, image sitemap, robots/noindex and exclusion of private/draft pages.
- [ ] **SEO-02** [AUTO] Prepare search launch checklist and approved-art social preview mechanism; keep indexing closed.
- [ ] **SEO-03** [OWNER — MANUAL] Approve actual artwork/copy/social preview and authorize public indexing; verify Search Console ownership if external access is needed.
- [ ] **SEO-04** [AFTER — BLOCKED] Enable indexing after public launch approval, verify sitemap submission and Search Console coverage; verify no private pages are indexed. Depends SEO-03, OPEN-07.

### Staged public opening

- [ ] **OPEN-01** [AUTO] Prepare concise artist onboarding walkthrough, daily operating checklist, support/privacy handling, launch-readiness evidence template and rollback checklist.
- [ ] **OPEN-02** [AUTO] Audit owner-facing readiness reports; distinguish configuration from external evidence and keep all commercial gates unchanged.
- [ ] **OPEN-03** [AUTO] Complete autonomous backlog; identify remaining owner/conditional tasks and hand off one consolidated manual decision list. Do not mark owner tasks complete.
- [ ] **OPEN-04** [OWNER — MANUAL] Complete visual pass and approve final requested changes, art, pricing, legal policies, payment and recovery; ensure support/privacy inbox monitored.
- [ ] **OPEN-05** [AFTER — BLOCKED] Complete owner feedback and controlled onboarding/request/payment/delivery/privacy pilots; verify rollback and actual external evidence. Depends preceding required stages.
- [ ] **OPEN-06** [AFTER — BLOCKED] Run final five-workflow release verification, exact live deployment and readiness/integrity/backup checks after final changes. Depends OPEN-05.
- [ ] **OPEN-07** [OWNER — MANUAL] Explicitly authorize staged commercial opening and indexing separately; choose conservative initial capacity.
- [ ] **OPEN-08** [AFTER — BLOCKED] Enable approved gates in order, monitor initial requests/mail/payments/delivery, pause on incidents; expand only after review. Depends OPEN-06/07.

### Optional / not launch requirements (unless owner selects)

- [ ] **OPT-01** [OPTIONAL] Automatic hosted invoice creation, automated overdue reminders and accounting integrations.
- [ ] **OPT-02** [OPTIONAL] Deposits/installments, partial refunds, direct provider refund initiation and formal paid scope amendments; require owner business decisions/provider verification first.
- [ ] **OPT-03** [OPTIONAL] Automated watermark options, genuine customer reviews/testimonials, availability calendar, discounts, wishlists and gift certificates.
- [ ] **OPT-04** [OPTIONAL] Multilingual content, privacy-reviewed analytics, physical shipping/tracking and additional staff roles only if business expands.

## Pass log (append newest entries below; do not overwrite history)

### 2026-11-26 — Session XXVI / pass 01 (started)

- Created canonical checklist and established baseline. Next: REG-01/02, inspect Chromium failure before changing code or test; verify and record results.
- All OWNER/AFTER/OPTIONAL items deferred. No production changes authorized.
