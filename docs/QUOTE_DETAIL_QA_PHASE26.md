# v3.8 — Safer quote decisions and request-detail refresh

Implemented November 24, 2026. Public registration, commission intake, quote delivery/responses and payment collection remain controlled by existing server-side feature gates.

## Customer quote review improvements

- A customer must check **“I have read this quote…”** before the **Accept quoted terms** control becomes clickable. Server-side acknowledgment, current-policy, expiry, ownership and CSRF validation remain authoritative; UI restrictions are defense-in-depth.
- A failed quote response (for example an expired version, rate limit or disconnected request) now **restores the eligible action buttons**. Previously, the buttons were left disabled after a failed response until the request detail was reopened.
- Quote statuses are human-readable: **Awaiting your decision**, **Accepted**, **Declined**, **Replaced by a newer quote**. An expired sent quote is explicitly marked expired and provides no accept/decline controls. Replaced quotes and any historical quotes also remain read-only.
- A pending quote decision temporarily disables further decisions and the new refresh button to reduce accidental duplicate requests.
- Successful quote response refreshes only the originally selected request; if the customer navigates to another request while the decision is pending, its response does not force navigation back.
- The wording continues to make clear that quote acceptance neither creates an invoice nor automatically charges the customer.

## Request-detail reliability and privacy

- **Refresh details** is available next to Close.
- The private detail body stays concealed during loading and on failed fetches, rather than showing stale details from a previously selected request.
- A live region reports loading errors and allows a refresh retry without losing the request-history list.
- A per-request sequence guard ignores late detail responses from a previous selection, including the same request being refreshed multiple times.
- Closing the detail invalidates outstanding fetches and hides its content.
- Updated mobile styling keeps the refresh and close controls at practical tap sizes.

## Automated QA

The existing `tests/browser_commission_smoke.cjs` suite now also checks:

- Quote acceptance disabled until acknowledgment; decline remains independent.
- Failed mock quote response preserves CSRF/idempotent payload fields and restores both actions.
- Reopening a quote resets the acknowledgement to unchecked.
- Even if a malformed synthetic response says an expired/superseded quote can respond, the browser renders it read-only.
- A delayed response for request A cannot replace the newer selection B.
- A temporary detail-server failure hides its private content and can recover through Refresh.
- All tests use fake accounts, quotes, orders and intercepted local-only HTTP calls; **no production customer account or real quote was modified**.

## Outstanding

Controlled real-customer browser verification, policy approval, genuine portfolio artwork and independent off-site backups remain separate launch requirements. Server-side quotes and orders have already been covered by disposable MariaDB tests.
