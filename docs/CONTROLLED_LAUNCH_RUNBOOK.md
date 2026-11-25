# Controlled launch runbook

## Current state

All five hosted-invoice suites passed on d5222f26. Additive production schema 006 was applied successfully by workflow 45544817268. All commercial switches remain closed. This pass adds invoice follow-up and consistency checks, requests a new encrypted local backup, and adds an uninterrupted HTTP commission journey starting with a verified synthetic account. Synthetic invoice redirects are not followed; there is no outside provider, charge, email or production customer. This is not evidence that real registration/CAPTCHA/SMTP or a payment provider works end-to-end.

## Stage 0 — Owner decisions and independent recovery

- Confirm real portfolio artwork and permissions, design, service scope, actual prices/currency, licensing, revisions, refunds, privacy/retention and final legal text. Publish immutable policy bundle and pin its digest only after approval.
- Select provider and receiving account. Verify that an order-specific invoice can charge exactly the agreed total once, cannot be adjusted, tipped or subscribed, and is compatible with the restricted host/path grammar. No API or webhook is connected yet.
- Configure independent encrypted backup destination and key custody; run isolated recovery outside the hosting account. Local encrypted backup alone is not enough.
- Before any opening, review live readiness, integrity and backup indicators and verify deployment commit. Retain a rollback configuration outside public_html.

## Stage 1 — Controlled onboarding

Enable only the minimum approved registration/auth gates for a bounded test period. Keep intake paused and all payment/delivery switches closed. Exercise registration with an authorized test identity, real email verification, CAPTCHA, login, password reset and logout. Check both customer and admin isolation. No real customer records used as test fixtures.

## Stage 2 — Controlled request and quote

With approved policies and an eligible test account, enable reviewed request/reference/message gates. Set capacity to one and open intake only for the controlled test window. Verify submission, duplicate retry, private image access, artist review, quote acceptance and separate unpaid-order confirmation. Do not accept a live payment before terms and provider verification.

## Stage 3 — Hosted provider validation

Use the provider's test environment if available. Confirm the receiving merchant, exact amount and currency; test failed/expired payment, duplicate attempt, external closure and local suspension. A return URL or redirect is not proof of payment. Manual reconciliation requires independent provider-account verification; a real transaction, if needed, requires owner authorization. Never enter raw card data on DrawMeOne. Automated verification requires provider-specific authenticated API/webhook contract, signature replay defense, account binding, unique provider event/transaction IDs, amount/currency/status checks, dispute handling, retry and audit design. Do not activate generic unverified webhooks.

## Stage 4 — Delivery and operations

Test production transition, proof, included revision, final release and download with two accounts. Verify payment hold denies future download and restore only after settlement. Review privacy workflow, retention inventory, invoice follow-up, backups and integrity monitoring. Confirm staff can handle exceptions without deleting records or issuing unapproved refunds.

## Stage 5 — Public opening and rollback

Owner approves readiness and explicitly opens selected gates. Intake starts paused if the capacity feature is enabled. Initially use a conservative capacity. If an incident occurs, pause intake first, then disable affected commercial action switches while preserving existing customer history where possible. Do not delete existing data or rotate the application key as a rollback. Escalate provider closure separately; local disabling cannot cancel external invoices. Review active orders and verified payments before any policy or key change. Retain test evidence and document the decision to open.
