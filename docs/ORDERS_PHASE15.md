# DrawMeOne v2.6 — Unpaid order confirmation and read-only status

Implemented November 23–24, 2026. **Disabled for public customers and artists until Terms/Privacy and business rules are approved.**

## What changed

The existing production schema contains `dmo_orders` and `dmo_payments`; their columns were verified using read-only Hostinger SSH diagnostics. No schema change or payment-service credential is required for this stage.

- `POST /api/admin/order-create.php` lets the authenticated artist explicitly confirm **one** order after the verified customer accepts a quote. It checks the exact accepted quote, its request ownership and policy version, customer verification, request status, duplicate orders, a separate admin order switch, terms approval, same-origin JSON, CSRF, IP/account rate limiting and transaction serialization.
- The new order is always inserted as `status='awaiting_arrangement'`, `payment_status='unverified'`. **There is no payment insert, charge, email, invoice, subscription, checkout link, payment-status upgrade or payment-provider request.**
- The artist's request detail view displays actual order records and the accepted quote amount; the confirmation button appears only if the server-side order gate is open, there is an accepted quote and no order exists.
- The customer's existing private request detail now displays their own actual order(s), quote amount, order state and payment state, without exposing provider references, private payment notes, other customers or administrative information.
- No order or customer record is fabricated or seeded. The operation returns an audit event with order/quote IDs but no customer private data.

## Private gates

```dotenv
DMO_ORDER_ADMIN_ENABLED=0
DMO_ORDER_TERMS_APPROVED=0
DMO_REQUEST_ADMIN_WRITES_ENABLED=0
DMO_AUTH_ENABLED=0
DMO_PRIVACY_PUBLISHED=0
DMO_REQUEST_TERMS_APPROVED=0
```

`DMO_ORDER_ADMIN_ENABLED` and `DMO_ORDER_TERMS_APPROVED` are new, intentionally **unset** production switches; they will never open simply because this source code is deployed. The server additionally requires customer auth and an approved request policy revision.

## Further testing and milestones

- [ ] Controlled verified customer/artist test to accept an unexpired released quote, confirm one unpaid order, retry and verify no duplicates, confirm customer isolation and valid account audit.
- [ ] Record and show due dates only after individual agreement; never invent a promised delivery date.
- [ ] Design a payment-confirmation process that verifies provider-side records (or rigorously reconciled manual bank records) before changing `payment_status`.
- [ ] Allow explicit, audited artist order state changes only after payment and policy requirements, with separate deliverable upload/access controls and refund policies.
- [ ] Publish artist-approved Terms/Privacy and commercial policies before enabling order confirmation or charge collection.

Customer registration, commission submissions, payments and order confirmation are **not** activated by this implementation.

## Phase 33 — Artist request isolation

The admin request detail now clears unsaved note/message/quote drafts when switching requests or closing details, clears previous customer data before fetching new details, and uses a monotonic request generation to ignore late replies even when revisiting the same ID. Failed reads leave a blank, disabled detail rather than showing another customer's information. Switching/closing is blocked while a request mutation is in flight; same-request refresh preserves unsaved fields. Input controls are disabled during loading and mutation. Browser tests use synthetic customers and assert no POSTs, cross-request draft isolation, failed loads and late response isolation. This deliberately discards unsaved edits when changing request; no server mutation or payment logic changes. Initial CI verification pending.

## Phase 34 — Commission search and pagination

The Artist Desk request list now supports server-side literal reference/customer-name search, a needs-review/other/all filter and 40-record pages. Queries are bound, authorization is checked before validation or retrieval, and result fields remain unchanged. A stable submitted-date/ID ordering and a 41st-row lookahead determine whether Next is available; this is offset pagination, so new insertions can shift records between pages. Filters are held in browser memory only. No private notes, emails or payment fields are added to the list. The new tests cover unauthorized access, literal wildcard search, invalid pages, a multi-page synthetic dataset and mobile browser controls. No production gates changed. Initial CI pending.

## Phase 35 — Fresh admin session boundary

Successful logout (server confirmation required) and authenticated API session expiry now immediately hide the private workspace and replace the entire document with a fresh sign-in page. Old request generations are invalidated and no previous DOM or unsaved draft survives. The fresh sign-in initializes Turnstile even when the initial page loaded an existing session; a transient reason query parameter is removed from the address bar. Pagehide hides the workspace and persisted history restoration reloads the page. The reason marker affects presentation only, not server authorization, and no new session is created by it. A mock-browser test verifies successful logout and expiry, document replacement, absence of the old artist identity and CAPTCHA initialization. The existing server-side logout endpoint remains unchanged. CI and live checks pending.

## Phase 36 — Customer private workspace isolation

Customer replies and selected reference files are reset when switching or closing a request. Previous brief and message content is cleared before the next detail loads. A common mutation guard prevents switching or closing while quote, message, reference upload or removal operations are active. A 401 from any private commission API hides the workspace, invalidates request generations and replaces the document with the account page. Persisted browser history reloads rather than restoring private content. Synthetic browser tests cover draft/file isolation and session expiry; real account onboarding and policy approval remain launch blockers. Initial CI pending.
