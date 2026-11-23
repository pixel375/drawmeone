# Draw Me One — v1.6 Artist commission review (Phase 6)

Updated November 23, 2026. Admin-only, **no customer-facing writes or quote sending**. Built against the original MySQL `001_initial_schema` tables for requests, conversations, messages, quotes, lines, and audit records. No new SQL migration is required.

## What's implemented

- **Orders & quotes → View brief & drafts:** A protected full request detail view with the customer's exact brief, contact preference, deadline, customer email, selected tier/add-ons, conversation history and quote versions.
- **Messages → View request & thread:** Navigate from a recent message to its related commission request.
- **Private artist notes:** New notes are saved as `dmo_messages.is_internal=1` in one conversation per request. They are not emailed or shown to customers.
- **Versioned quote drafts:** Artist sets CAD/USD, artwork scope, revision count and 1–12 description/amount lines. Server validates values and calculates total from integer minor units. Every saved draft gets a new `dmo_quotes.version_number` and lines in `dmo_quote_lines`; no edit-in-place or deleting history.
- Reuse a previous draft's scope and line items as a starting point for a new revision. Prior versions stay visible.
- Audit events record which administrator added an internal note or draft and the new record ID, without logging customer text or quote contents.
- Strict auth: HTTPS/same origin, verified active admin role and short session, CSRF on POST, immutable request lock, transaction rollback and prepared SQL.

## Server gate (disabled by default)

In the existing **private** Hostinger `.env` outside public_html:

```dotenv
DMO_REQUEST_ADMIN_WRITES_ENABLED=0
```

When absent or 0, `GET /api/admin/request.php?id=...` works for authenticated admin but both note and draft buttons remain disabled and **POST is rejected with 503**. No account, request, quote, message, or payment can be created by the public site.

After controlled QA, set `DMO_REQUEST_ADMIN_WRITES_ENABLED=1` to allow **admin-only** private notes and internal quote drafts. This is independent of customer registration, public commission intake, site pricing, quote publication, payment verification or external messaging, all of which remain disabled.

## API contracts

`GET /api/admin/request.php?id=INTEGER` -> full request, messages, past quote versions and safe display-only addon names, plus write and send flags. Requires a verified admin session. Does not reveal file storage keys, private attachments, or raw tier/addon snapshot JSON.

`POST /api/admin/request-actions.php`: JSON, authenticated admin, origin `https://drawmeone.com`, `X-DMO-CSRF` session-derived token.

Two accepted actions:

```json
{"action":"add_private_note","request_id":1,"body":"Private artist reminder"}
```

```json
{
  "action":"create_quote_draft","request_id":1,
  "currency":"CAD","scope":"Full color portrait, digital delivery",
  "revision_allowance":1,
  "lines":[{"description":"Illustration","amount_minor":5000}]
}
```

No `send_quote`, `accept_quote`, `create_order`, `pay`, `upload_attachment` or `email_customer` actions exist in this phase.

## Checks to perform before opening customer intake

1. Confirm live `/api/health.php` remains connected and public `/api/catalog.php` reports commissions closed.
2. Verify unauthorized direct GET/POST return HTTP 401; rejects CSRF, HTTP origins, unsupported actions, malformed amounts and missing requests. Do not remove or weaken authentication to perform tests.
3. Verify PHP syntax with read-only Hostinger SSH diagnostic. Inspect `request.php`, `request-actions.php`, `commission-workflow.php` along with existing admin security files.
4. With a controlled **real request** in the database, test internal note saving and quote draft creation. Confirm no customer sees the note or quote, no message/email is sent, no order/payment records are created, and audit entries record only IDs and action names.
5. Check desktop/mobile layout, keyboard focus and session expiration. Avoid using real client PII while the public repo is being reviewed.

**Not end-to-end verified yet:** There are currently no submitted real requests because public registration and intake are still disabled. This phase provides the artist-only review and drafting foundation. Do not claim that customer commissions are open.

## Next planned phases

- Secure logged-in request submission with visible policies and the approved catalog, starting as private text without reference files.
- Private customer messaging, quote delivery/acceptance and explicit payment approval.
- Real artwork uploader with validated images, private storage, optimized derivatives and consent checks; remove the demo gallery when real artwork has been approved.
