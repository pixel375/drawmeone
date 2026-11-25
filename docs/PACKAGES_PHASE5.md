# Draw Me One — Phase 5: commission tier and add-on editor

Updated 2026-11-17. The full original database migration `001_initial_schema` has been checked for the exact tier and add-on columns. **No new SQL migration or new database is needed.**

## Current live building blocks

- A secured `GET /api/admin/packages.php` returns all tiers/add-ons **to a verified administrator only**, including unpublished records.
- A secured `POST /api/admin/packages.php` can create or update a tier/add-on, or deliberately change publication status, after separate server feature switches are enabled.
- Admin dashboard: **Artist's Desk → Services & pricing**. Choose existing entries, create a new entry, edit it, reload, save or intentionally publish/unpublish.
- Fields: slug, display name, description, CAD/USD, estimated minimum/maximum, sort order; tiers also have an editable list of up to 20 included features.
- Existing public `GET /api/catalog.php` still returns only `is_active = 1` tiers/add-ons. No packages, rates or testimonials are inserted by this deployment.
- No deletion endpoint. A package referenced by an old commission remains in the database for history; take it off the public catalog using Unpublish.
- No automatic payment, client signup, quote acceptance, private upload or order writes are enabled by this phase.

## Security and pricing gates

Three independent private .env switches, **all off by default**:

```dotenv
DMO_PACKAGE_WRITES_ENABLED=0
DMO_PACKAGE_PUBLISH_ENABLED=0
DMO_PRICING_APPROVED=0
```

- **Writes**: when enabled, an authenticated admin can create and edit *unpublished* tiers/add-ons, protected by strict same-origin HTTPS JSON, CSRF, role/session checking, input validation, prepared SQL statements, transactions and audit logging.
- **Publishing**: requires **all three** switches set to 1. This avoids publishing unapproved sample price ranges. The request must also use an authenticated admin session and valid CSRF token. Publicly active items need a description and complete min/max estimate.
- Editing a **published** item also requires publishing to be enabled, since changing it otherwise modifies public prices without a second publish action.
- No direct client POST to public `/api/catalog.php`; that API stays GET-only. Rates are integer minor currency units (e.g., `500` for $5.00), not floating-point database amounts.
- Only CAD/USD accepted for the first iteration. Additional currencies can be supported with an explicit product decision and FX/tax treatment.
- Unique database slug constraint prevents duplicates. No one can grant themselves admin access from these routes.
- The API and the dashboard intentionally do **not** seed Simple $5-$10, Normal $15-$20 or Advanced $25-$30. These were unapproved project examples.

## Setup order

1. Turnstile keys and app key have been detected by the safe status API, but **a real challenge has not yet been tested**.
2. Keep `DMO_ADMIN_ENABLED=0` while testing and reviewing admin code.
3. Provision the initial admin using the **CLI-only** `app/cli-admin.php` on the Hostinger server, as documented in `ADMIN_PHASE3.md`; do not send passwords or session cookies to chat.
4. During a controlled test, enable admin login and check Turnstile + session security. The owner explicitly requested **no MFA**, so admin protection is password, Turnstile, rate limiting, CSRF and short sessions.
5. Enable `DMO_PACKAGE_WRITES_ENABLED=1` only after admin sign-in QA. With the other two flags at 0, you can create unpublished drafts.
6. Confirm actual currency, package descriptions, deliverables, revisions and all intended prices with the artist; then set `DMO_PRICING_APPROVED=1` and, separately, `DMO_PACKAGE_PUBLISH_ENABLED=1` for controlled publication testing.
7. Verify public `/api/catalog.php` includes only published packages, and that unpublishing removes the record from the catalog without deleting history.
8. For first launch, remove the public Design Lab and connect the real commission frontend to approved catalog data. The current static commission-preview page still displays **demo-only** placeholder ranges.

## QA checklist before turning on either write flag

- [ ] Real admin login succeeds after valid Turnstile challenge; wrong password and wrong/expired CAPTCHA fail.
- [ ] Unauthenticated admin GET and POST are rejected; no customer account can use admin APIs.
- [ ] CSRF is required and verified for POST.
- [ ] POST fails when write flag is 0, and publication fails until both publication switches are 1.
- [ ] Invalid slugs, non-integer currency amounts, negative/oversized prices, unknown fields and duplicate slugs fail without saving.
- [ ] New tiers and add-ons are initially inactive, and private editing does not leak unpublished records publicly.
- [ ] Price changes to active records cannot occur when publishing is locked.
- [ ] Create, edit, publish and unpublish all generate audit records.
- [ ] Phone and desktop layout, keyboard navigation, error feedback and session expiry are tested with a controlled artist account.

**Important:** The dashboard and the endpoints have been implemented; they are not end-to-end verified with an authenticated administrator yet. Do not call them production-ready until this checklist passes.

## Catalog publication integrity follow-up

Pricing approval now gates every public catalog read and every new selected package/add-on request; custom requests and idempotent retries remain available under their own gates. Incomplete or invalid active offers are filtered, and active edits must retain complete price ranges and descriptions. Catalog intake status now reflects pause/capacity. Tier restrictions are computed independently of published add-ons, without the old global mapping truncation. Existing items return a private content revision; every edit or visibility change must match the locked row revision, with reload on conflict. The dashboard disables concurrent writes and retains historical request snapshots. No pricing approval or production flag was changed. Disposable HTTP coverage added for real draft/publish/unpublish and stale edits. Existing clients using admin catalog writes must reload the updated dashboard.

## Artist-managed optional-extra compatibility

The existing tier/add-on mapping is now editable in Artist's Desk, with separate controls for all current/future extras versus selected extras (including none, when at least one extra exists). Draft compatibility stays private; active-tier changes require approved pricing and publishing gates. Updates require both the tier revision and a mapping/inventory revision. Explicit publication also verifies the current mapping revision. Writes are transactional, CSRF-protected and audited. Unpublished extras never appear to customers. Restricted tiers exclude future extras until selected. Editing is refused rather than partially saving when the bounded inventory exceeds 100 extras. No migration, price approval, new live data or launch switch was changed. Tests exercise actual HTTP endpoints and mocked artist browser interaction.


Pricing editor now tracks unsaved package fields and extra compatibility independently. Changes survive tab navigation; selecting a different item or reloading requires confirmation. Publish and other conflicting actions are blocked until pending changes are saved or discarded. Failed writes preserve form inputs and uncertain network outcomes require a fresh read. No rates or approval switches changed.
