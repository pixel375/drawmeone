# DrawMeOne V2 — Master TODO (Back to Basics)

Canonical checklist, replaces the completed V1 master checklist. V1 preserved on [archive/v1-commission-platform](https://github.com/pixel375/drawmeone/tree/archive/v1-commission-platform) at e5bb816. Do not revive V1 customer accounts, formal orders, quotes, proof approvals, finance or customer management. Preserve data, existing admin credentials, private image storage, SMTP and encrypted backups; do not delete historic tables or files without a reviewed recovery/retention decision.

## Rules for every development pass

- Work directly on **main**, the live production portfolio. No demo modes, mock art or design-lab UI. Public homepage is real and usable; commission intake remains closed during development. Never automatically open it.
- Four artist sections: Artwork, Requests, Payments, My website. Minimal controls, optional metadata, no customer login. New features must solve a real artist/visitor need.
- Read current main, workflow evidence and this list; resolve defects first, make substantial passes, record evidence per completed item and append dated pass notes. Leave pending CI unchecked. Use synthetic data only in disposable tests, never production.
- Do as much independently as possible. Owner decisions and real external tests are deferred until independent work is done. No fabricated art, secrets, live test payment or unsolicited external email. Avoid unnecessary architecture and feature expansion.
- Keep public pages accessible and mobile-first; protect all admin/private data. Never represent a configured service as a proven external service.

## Independent implementation

- [x] **V2-01** Archive V1 and replace the old TODO/master direction. Evidence: archive/v1-commission-platform created at e5bb816; this checklist and README replace old active guidance.
- [ ] **V2-02** Replace public homepage with a warm personal gallery, real published drawings only, simple contact, theme toggle and honest empty/loading/error states; preserve complete-image viewer. Initial implementation in this pass; verify browser and live release.
- [ ] **V2-03** Replace Artist Desk with four simple sections and secure sign-in; preserve existing upload/auth/session and private drafts. Initial implementation in this pass; verify live and browsers.
- [ ] **V2-04** Make artwork upload, bulk publish/unpublish, ordering, optional description and full-image preview effortless. Initial upload/publish/feature implemented; bulk publish, sorting, description and preview refinement next.
- [ ] **V2-05** Simple website settings: intro, social links, basic appearance, optional starting prices and accepted payment methods; no private payment details. Initial validated versioned settings implemented; verify real disposable DB and stale update denial.
- [ ] **V2-06** Build guest commission enquiry (name/email/idea, optional budget), spam defense, rate limits and secure server-side availability check; closed default, no account.
- [ ] **V2-07** Build a minimal private request inbox (new/read/archived), email reply and notification, safe persistence/retention. Add optional reference upload only if secure and simple; no quotes/orders.
- [ ] **V2-08** Implement genuine accepting-commissions toggle, off by default, server enforced, no legacy commercial activation. Keep closed during development; test both states exclusively in disposable CI.
- [ ] **V2-09** Finish V2 payment presentation, no checkout or stored financial secrets; agreed private invoice/payment instructions, accepted method names/optional starting price.
- [ ] **V2-10** Retire V1 public/customer routes, mock assets and navigation; verify old URLs redirect or deny, no legacy APIs accessible. Initial guards implemented; confirm live and remove obsolete active references.
- [ ] **V2-11** Simplify artwork detail/directory and privacy notice; verify public SEO, noindex private/admin and published-only sitemap. Homepage indexable as live site; secondary pages remain noindex pending review. No invented legal claims.
- [ ] **V2-12** Replace outdated UI checks with real V2 browser tests on Chromium/Firefox/WebKit, accessibility and mobile; preserve useful server-side legacy recovery/security contracts until safely retired. Initial V2 tests implemented; verify and extend.
- [ ] **V2-13** Verify production exact release, guarded endpoints, admin readiness, private image integrity, backup and restore, guest closure. Update diagnostics to V2; initial implementation pending live results.
- [ ] **V2-14** Final simple artist walkthrough and concise owner handoff; stop expansion when independent checklist complete.

## Owner / after independent completion

- [ ] **OWNER-01** Review actual desktop/mobile design, artist workflow and submit feedback; upload/publish original approved artwork.
- [ ] **OWNER-02** Approve real artist bio, public social/contact, accepted payment methods and optional actual starting prices.
- [ ] **OWNER-03** Review applicable privacy, rights, licensing, pricing/refunds and contact/retention obligations; no unapproved legal terms or actual payment actions.
- [ ] **OWNER-04** Choose independent encrypted backup destination and separate key custody (existing issue #1); verify independent recovery.
- [ ] **OWNER-05** Authorize controlled real mail/guest request and security pilot, review actual device/accessibility and artwork; resolve feedback.
- [ ] **OWNER-06** Explicitly authorize accepting commissions after verification. Until then leave closed.

## Pass log

### 2026-11-27 — V2 pass 01
- Archived V1 at e5bb816; new direction and master checklist. Replaced homepage and Artist Desk with real V2 UI; no demo artwork. Initial secure versioned public settings, retained original admin/auth and artwork pipeline. Requests remain intentionally closed; actual guest request/inbox/toggle not yet implemented. Retired V1 routes blocked or redirected by Apache, data and internal recovery code retained. New browser contracts and exact-deployment diagnostics. Commit and workflow evidence to be reconciled next pass.

- First implementation 4f223b7 source 45944247109 passed. Hostinger 45944246996 confirmed exact files stable, then failed because inherited CLI admin check required an email argument; corrected to safe aggregate readiness probe (no email or credentials). Added simple bulk selection and publish/private actions, optional per-art description/position/featured controls under More options and full-size private preview. Added disposable settings CSRF/stale/allowlist/default-closed tests and browser bulk controls. Await cumulative CI and live confirmation.
