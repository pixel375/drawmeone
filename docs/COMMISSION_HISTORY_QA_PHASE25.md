# v3.7 — Private commission history usability and browser coverage

Implemented November 24, 2026. Customer registration, new commission intake and payments remain closed in production.

## Customer experience

The signed-in commission page now provides:

- A search field to find a request by its private reference, drawing type/package or displayed status.
- Status filtering for **All requests**, **Active requests**, and **Completed or closed**.
- A visible count such as **Showing 2 of 3 recent requests** and a distinct no-matches message.
- Clearer customer-facing status wording instead of raw internal database labels.
- Responsive controls for narrow phones, with minimum 44px targets and labelled fields.
- A refresh-sequence guard preventing a slower old request-history response from overwriting newer results.

The search/filter operates **only in browser memory** on the 40 most recent requests already returned to the authenticated user. It introduces no new API, cross-customer access, localStorage, URL query parameters, analytics events, or customer brief persistence. Public visitors never see these controls while the service is closed.

## Automated checks

`tests/browser_commission_smoke.cjs` extends the existing Chromium CI workflow. With synthetic requests, private quotes and unverified orders, it verifies:

- Signed-in customers retain read-only request history while new commission intake is closed.
- Search and active/closed filtering work on phones, tablets and desktop.
- A request detail displays its private brief, messages, quote line items and distinct unverified payment status without accepting a payment.
- Reference upload and private messaging controls stay hidden when their server-side switches say disabled.
- When synthetic intake is opened, a verified customer sees the request form and its compatible packages and add-ons; a valid submission sends one POST to an intercepted mock backend with a UUID idempotency key.
- An unauthenticated visitor sees the sign-in requirement and cannot access the submission form.
- No write requests are issued during the read-only history checks.

This is a **simulated browser flow**, not approval to enable real customer accounts or pricing. Real server-side request authorization and idempotency remain covered separately by disposable MariaDB integration tests.

## Remaining launch work

Controlled customer account signup, real CAPTCHA and SMTP pilot, actual artist-approved artwork and legal policies, and independent encrypted off-site backups with separate recovery-key custody are still outstanding. No customer-facing feature switches were changed.
