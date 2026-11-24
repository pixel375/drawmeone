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
