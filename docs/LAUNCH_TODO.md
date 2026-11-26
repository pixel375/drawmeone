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

- [x] **REG-01** [AUTO] Resolve Chromium privacy-history regression: distinguish failed older page from failed current refresh; preserve drafts and fail closed. **Evidence:** af6b2dd; Chromium 45849866489 and Firefox/WebKit 45849866392 passed; privacy refresh race resolved without modifying production privacy logic.
- [x] **REG-02** [AUTO] Extend privacy regression coverage: explicit retry, cursor/deduplication, status gating, UTF-8 and uncertain draft preservation. **Evidence:** af6b2dd; same browser runs plus source 45849866382 and disposable DB 45849866385 passed.
- [x] **REG-03** [AUTO] Audit recent CI failures, fix actual regressions; rerun relevant source, DB, Chromium, Firefox/WebKit and live deployment checks. **Evidence:** Audited latest 100 main workflow runs. Prior failures: Chromium 45765478164 (resolved above), Hostinger 45751809915 (SSH timeout) and 45753515092 (missing CLI during sync); exact-release stabilization subsequently passed 45765519146 and 45852440448. All four af6b2dd suites green. New work tracked under REG-04.
- [x] **REG-04** [AUTO] Verify exact release and workflow evidence; resolve additional defects. Final full release verification repeats after changes (see OPEN). **Evidence:** 7f07947 all five suites passed (source 45852440137, DB 45852440294, Chromium 45852440211, Firefox/WebKit 45852440397, Hostinger 45852440448). Exact 42-file release stable before/after. Later test-only b5cd907 WebKit timeout separately tracked ART-03; repeat final release under OPEN-06.

### Authentic portfolio

- [ ] **ART-01** [OWNER — MANUAL] Supply/approve original artist drawings and publication rights; do not use reference collages or fabricated artwork.
- [ ] **ART-02** [OWNER — MANUAL] Upload/publish initial collection (suggested 6–12+), select featured art, arrangement and watermark preferences.
- [ ] **ART-03** [AUTO] Verify bulk zero-required-metadata upload, draft/publish, retries, ordering, optional metadata, image orientation and complete-image viewer with synthetic fixtures. **Pending CI / follow-up:** b5cd907 source/DB/Chromium/Firefox passed; WebKit run 45852529793 timed out in extreme-ratio fixture. 082a2aa replaces SVG mock with real PNG raster fixture, keeps geometry/dimension assertions, adds failure state and corrects viewer feedback grid. Chromium 45858794865 / compatibility 45858794847 pending.
- [x] **ART-04** [AUTO] Verify public mode never displays demos, private drafts or legacy/unapproved artwork, including failures. **Evidence:** 7f07947 all five suites passed; 45852440211/45852440397 confirm zero demo requests in public mode, legacy exclusion, error fallback/retry; DB 45852440294 proves draft/unpublish denial. Hostinger 45852440448 verified exact release.
- [ ] **ART-05** [AFTER — BLOCKED] Review actual published art, portrait/landscape rendering, thumbnails, full-view and ordering with artist on real devices. Depends ART-01/02.

### Public appearance and content

- [ ] **DES-01** [AUTO] Audit and refine Studio-first desktop/mobile layout, responsive navigation, typography, spacing, dark/light and gallery usability without publishing settings. **Pending CI / follow-up:** 7f07947 gallery progress across layouts verified. 082a2aa restores all Studio mobile main nav links with 44px controls, fixes explicit viewer rows. efbb48c adds public navigation assertions. a979b61 shares theme with customer pages. Broader final visual owner check remains DES-08/QA-04; automated checks pending.
- [x] **DES-02** [AUTO] Verify public mode hides Design Lab and alternative experiment controls; retain protected artist appearance options. **Evidence:** Reviewed studio.js: public settings require allowlisted Studio, preview controls hidden; stale local preview settings cannot override public presentation. Chromium 45849866489 + Firefox/WebKit 45849866392 passed published-presentation control and fallback tests. Protected preview remains available; owner publication not changed.
- [x] **DES-03** [AUTO] Audit obsolete previews/status endpoints; restrict or retire unsafe public development surfaces while preserving documented internal tooling. **Evidence:** 082a2aa retires old demo forms/setup pages in favor of real gated workspaces (original prototypes in Git history); health endpoint generic and no misleading writes flag. Source 45858794916, DB 45858794846, live 45858794841 passed; four live redirects confirmed. Private SSH diagnostics preserved.
- [ ] **DES-04** [AUTO] Prepare editable homepage introduction, headings, CTAs, brand and contact presentation where missing; avoid invented artist claims. **Pending CI / follow-up:** efbb48c adds six optional plain-text homepage fields in versioned design draft/publish flow, old snapshots compatible, server/client UTF-8 validation, literal text output, protected support address and intake CTA. Synthetic HTTP/browser checks pending; no owner content saved/published.
- [ ] **DES-05** [AUTO] Verify branding assets, favicon, social preview fallback and theme consistency across pages. **Pending CI / follow-up:** 082a2aa prevents eager preview logo downloads in public mode; a979b61 adds 20-logo-pair/favicons XML checks, safe text-only default social metadata, customer theme toggle/persistence + light-mode axe tests; actual social image remains owner-blocked. Latest CI pending.
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

### 2026-11-26 — Session XXVI / pass 01 (implementation checkpoint)

- Checklist creation `e036351`; README entry point `b8b74f6`. Initial inventory: 96 tasks (47 AUTO, 21 OWNER, 24 AFTER, 4 OPTIONAL), 16 categories including optional enhancements. This setup is complete; the launch checklist deliberately tracks only remaining launch work.
- REG-01 / REG-02: inspected failed Chromium run 45765478164 and current account.js / onboarding test. Both older-page and current-refresh errors share the same case-status text. The old test waited for that already-present text, then could assert before the fresh GET completed; the existing current-refresh catch clears records, cursor and actions. No evidence yet of a production data-retention regression. Commit [af6b2dd](https://github.com/pixel375/drawmeone/commit/af6b2ddf0e2c32a258eab53dd78d22e5716459c1) changes test only: wait for the distinct current-history failure, count actual GETs, verify retry/overlap deduplication/non-decreasing cursor rejection, fail-closed export/actions, recovery, uncertain POST draft retention and password clearing. Existing UTF-8 assertion retained. No production JS changes or weakened assertions.
- Verification pending: source quality passed [45849866382](https://github.com/pixel375/drawmeone/actions/runs/45849866382) on af6b2dd. Chromium [45849866489](https://github.com/pixel375/drawmeone/actions/runs/45849866489), Firefox/WebKit [45849866392](https://github.com/pixel375/drawmeone/actions/runs/45849866392), and DB integration [45849866385](https://github.com/pixel375/drawmeone/actions/runs/45849866385) are in progress at latest check. No Hostinger diagnostics triggered yet for this test-only commit. Prior source/DB/deployment and Firefox/WebKit passed only on earlier commits; do not mark REG-01/02 complete yet. Next session/pass: inspect workflows for af6b2dd (Chromium + Firefox/WebKit; source/DB if triggered), fix any new failure, record actual run IDs and check off REG-01/02 only after verified. Then REG-03/04 and next AUTO items. If test-only path filters omit an applicable workflow, use authorized manual dispatch if available; do not fake CI success.
- All OWNER/AFTER/OPTIONAL tasks skipped. No artwork, policies, customer records, payment settings, production flags, indexing, secrets or backup custody changed. No manual action needed.

### 2026-11-26 — Session XXVI / pass 02

- Completed REG-01, REG-02: af6b2dd green in all four triggered workflows: source 45849866382, DB 45849866385, Chromium 45849866489, Firefox/WebKit 45849866392. No production privacy behavior changed.
- Completed REG-03: audit recent 100 runs found only the known Chromium failure and two prior Hostinger failures. 45751809915 timed out connecting to SSH; 45753515092 reached an in-place deployment before cli-config-review.php existed. Later exact-release stabilization and subsequent diagnostics succeeded. No unresolved evidence of a production failure from those earlier runs.
- Completed DES-02 based on actual prior browser evidence and source inspection: invalid/unavailable settings fall back to Studio, hidden controls cannot change public appearance, visitor mode remains independent. Preview settings are still accessible for owner use during development.
- REG-04 / ART-04 / DES-01: commit [7f07947](https://github.com/pixel375/drawmeone/commit/7f079474ace6cd02df3c37e950a6ae89ac2ed876) atomically changed six files. Studio and concept showcase markup now stores samples as inert data-preview-src attributes, not eager src; gallery assigns actual image sources only when the manifest/presentation permits. Shared gallery progress now moves between preview layouts. Added browser assertions for zero public demo asset requests and visible progress in every layout. Exact deployment manifest 35 → 42 files, includes gallery, concepts, studio, stylesheet, artwork/portfolio API and backend; manifest contract updated.
- 7f07947 source 45852440137 and [Hostinger 45852440448](https://github.com/pixel375/drawmeone/actions/runs/45852440448) passed. Live release stable before/after diagnostics; integrity clean, zero published artwork, registration/quote/order gates closed, policy not configured. DB 45852440294, Chromium 45852440211 and Firefox/WebKit 45852440397 in progress at last check.
- ART-03: reviewed existing real HTTP upload/publish/unpublish, CSRF/ownership, WebP, metadata conflict, orientation, ordering, duplicate/retry and browser gallery recovery coverage. Added test-only commit [b5cd907](https://github.com/pixel375/drawmeone/commit/b5cd9079f66154fa3fb19aff461f049f961d859d) for 5:1 portrait/landscape drawings and actual viewer geometry, object-fit, navigation/close/detail controls and focus restoration at narrow, landscape-phone and desktop viewports. Source 45852529693, DB 45852529762, Chromium 45852529954, Firefox/WebKit 45852529793 pending. No production art or settings changed. Actual device/artist art testing remains ART-05/QA-04.
- Next pass: reconcile both commits' workflow results; fix any geometry/gallery issue; complete REG-04/ART-03/ART-04 on evidence, continue DES-01 and DES-03–05. Do not conflate test-only commit with a changed live release. All OWNER/AFTER/OPTIONAL items deferred; no manual action required.

### 2026-11-26 — Session XXVI / pass 03

- Reconciled 7f07947: all five suites passed; completed REG-04 and ART-04. b5cd907 source 45852529693, DB 45852529762 and Chromium 45852529954 passed; Firefox job 140022329509 passed; WebKit job 140022329886 timed out in checkCompleteImageViewer line 330 waiting for intrinsic image size/status. Other WebKit suites passed. ART-03 remains open; do not assert a production viewer defect or resolution until new evidence.
- [082a2aa](https://github.com/pixel375/drawmeone/commit/082a2aa8b370377d21c51cbe1992f7a33ed404d2): 14 files, real raster PNG fixtures generated by canvas instead of SVG, diagnostic state on viewer timeout, explicit viewer feedback grid rows, 44px Studio mobile navigation with all four links, preview logo requests deferred until preview is verified, obsolete mock admin/commission/setup pages retired with safe fallback pages + Apache redirects, public health generic only, updated workspace links, expanded exact-release manifest and live redirect checks. Source 45858794916 and DB 45858794846 passed; Hostinger 45858794841 passed (all 4 redirects, release stable, zero published art, closed gates, clean integrity). DES-03 complete. Chromium 45858794865 and compatibility 45858794847 pending.
- [efbb48c](https://github.com/pixel375/drawmeone/commit/efbb48cdaf2572a6021f92f9a514058985effc64): 11 files, optional versioned homepage heading lines, artist introduction, portfolio button label and contact heading lines in private appearance editor; blank uses original copy, byte validation, unknown keys rejected, plain-text output, historical restoration, draft isolation, no changes to protected contact email or intake state. Tests for literal markup, invalid copy, unsaved fields/history, mobile nav. Source 45859062551, DB 45859062615, Chromium 45859062701, compatibility 45859062544, Hostinger 45859062481 pending.
- [a979b61](https://github.com/pixel375/drawmeone/commit/a979b61d01fc7325b4afd68b2ea0ed554c13b4e2): 11 files; shared light/dark customer theme (commission, account, recovery) uses existing visitor preference, responsive toggle, reset favicon, closed-page light-mode axe coverage, brand SVG pair/viewBox/path and favicon contract, neutral homepage social description without demo art. No actual artwork/social image chosen. Workflow IDs not yet listed at initial query. No actual public design settings or customer data changed.
- Next: reconcile all 3 pass 03 commits, resolve any WebKit fixture/geometry issue and any design/theme/CI regression, complete ART-03, DES-01/04/05 as evidence warrants. Continue CAT-01/02/03 and legal preparation. All OWNER/AFTER/OPTIONAL items deferred. No manual intervention required.
