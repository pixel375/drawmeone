# Draw Me One — v1.8 Customer Commission Intake (Phase 8)

Implemented November 23, 2026. Designed for **verified customer accounts and text-only commission requests**. This phase does **not** open registration, email sending, customer uploads, quote delivery, payments, or commissions on the live site.

## What was built

- New branded, mobile-friendly \`/commission.html\` with **real published tiers/add-ons** from \`GET /api/catalog.php\`, rather than speculative preview prices.
- Visitors see an informative closed screen until the owner enables the customer system. Customers must use a verified account.
- A customer can choose an active tier or **Custom**, choose supported active add-ons, describe their idea (30–6,000 bytes), optionally specify a future deadline, and choose onsite or email contact.
- The final acknowledgement makes clear the request is **not** an order, payment, guaranteed delivery date or fixed-price quote.
- New \`GET /api/requests.php\` returns only the authenticated customer's own request list (last 40).
- New \`GET /api/request.php?ref=DMO-...\` returns the customer's own request and **non-internal** conversation messages. It never includes internal artist notes, unsent quote drafts, payment records, attachment storage keys, or other users' requests.
- New \`POST /api/requests.php\` creates requests, linked add-on snapshots, an empty conversation and an audit record in one database transaction. It never sends a message or charges anyone.
- Safe **idempotency**: browser generates a random UUIDv4 request key and retains it for retries. Server derives an unpredictable per-customer reference using keyed HMAC; a repeated submission with the same key returns the existing request, rather than duplicating it.
- Two layers of rate limiting: existing database IP/customer rate limits plus a maximum **three requests per customer in a rolling 24 hours**, serialized by customer row lock.
- The backend validates all IDs, published tier/add-on availability, compatibility mappings, subject types, dates, contact method, UTF-8 JSON text, acknowledgement and policy version, all independently of the browser.
- The signed-in account page links to the new commission portal. The homepage links to it, but keeps **COMMISSION REQUESTS ARE NOT OPEN YET** unless the server's public status API says open.

No new database migration is needed; this uses the original \`dmo_commission_requests\`, \`dmo_request_addons\`, \`dmo_conversations\`, \`dmo_users\`, and \`dmo_audit_log\` tables plus migration 002 for rate limiting.

## Private configuration — all off by default

Place these in the existing private Hostinger \`.env\` **outside public_html**, never GitHub:

\`\`\`dotenv
DMO_AUTH_ENABLED=0
DMO_REGISTRATION_ENABLED=0
DMO_MAIL_ENABLED=0

DMO_REQUESTS_ENABLED=0
DMO_REQUEST_TERMS_APPROVED=0
DMO_PRIVACY_PUBLISHED=0
# Leave unset until approved policies are published:
# DMO_REQUEST_POLICY_VERSION=2026-11-artist-approved
\`\`\`

All the following must be true before \`dmo_requests_open()\` returns true:

1. Existing customer auth readiness \`DMO_AUTH_ENABLED=1\`, stable app key, Turnstile site+secret, \`DMO_MAIL_ENABLED=1\`, and a valid owned mail-from address.
2. \`DMO_REQUESTS_ENABLED=1\`.
3. \`DMO_REQUEST_TERMS_APPROVED=1\`.
4. \`DMO_PRIVACY_PUBLISHED=1\`.
5. A valid 3–40-character \`DMO_REQUEST_POLICY_VERSION\` representing a **real owner-reviewed terms/privacy revision**.

Registration remains independently gated by \`DMO_REGISTRATION_ENABLED=1\`. Enabling customer account login does not open new registrations.

**Do not set those legal approval flags until actual site policies exist and the artist has reviewed them.** The current commission preview is a demonstration only, not binding terms. A request is not a contract; a payment gateway and explicit quote acceptance are future phases.

## API contract

\`GET /api/request-status.php\` (public) returns \`requests_open\`, \`requires_account\`, \`text_only\`, and (when open) \`policy_version\`.

\`POST /api/requests.php\` requires the current verified customer \`__Host-dmo_session\` cookie, \`Origin: https://drawmeone.com\`, HTTPS, \`Content-Type: application/json\`, and \`X-DMO-CSRF\` from \`GET /api/auth/me.php\`.

Example (not a live request):

\`\`\`json
{
  "idempotency_key": "550e8400-e29b-41d4-a716-446655440000",
  "tier_id": null,
  "addon_ids": [],
  "subject_type": "original_character",
  "brief": "A cheerful character portrait with bright colours and a simple background.",
  "preferred_contact": "onsite",
  "preferred_deadline": null,
  "policy_version": "2026-11-artist-approved",
  "acknowledge_review": true
}
\`\`\`

The server never accepts prices, user IDs, order status, quote terms, message recipients or file paths from this endpoint. The sample policy version above is illustrative, **not** an approved value.

## Verification required before opening

- [ ] Existing admin account and artwork gallery continue working.
- [ ] Public \`/api/request-status.php\` shows \`requests_open:false\`; catalog continues serving approved published tiers only, no fake prices.
- [ ] Direct anonymous \`GET /api/requests.php\` and \`GET /api/request.php\` never expose requests. POST with customer auth off or with the request flag off cannot write.
- [ ] New PHP files pass syntax lint on Hostinger and frontend JS passes syntax checks.
- [ ] Complete actual Terms, Privacy Policy, commission scope/allowed content, refunds/cancellation, revision terms, timeframes, data retention and contact arrangements.
- [ ] Configure and verify a genuine outgoing email service for signup verification and password reset; check SPF/DKIM, inbox/spam placement and test expired/reused tokens.
- [ ] Controlled customer signup/login with valid and invalid Turnstile challenges, role isolation, CSRF rejection, session expiry and reset.
- [ ] Create one controlled customer request, ensure it appears in the artist inbox, verify idempotent retry, three-per-day limit, cross-customer data isolation, and that internal notes/draft quotes never reach customer APIs.
- [ ] Confirm the UI handles cancelled/closed intake without losing read-only customer request history.
- [ ] Add private reference-image attachments with size limits, re-encoding, no executable/SVG upload and explicit owner access controls *before* promising uploads.

## Notes on public GitHub repository

Hostinger uses a **private .env**; the GitHub repository is public, so no credentials, client attachments, originals, passwords or customer data may be committed. The new customer request records exist only in private MySQL. GitHub Actions uses environment secrets for SSH; no customer content is printed by diagnostics.

## Next phases

1. Owner-reviewed policy pages and tested email verification/recovery with a controlled account.
2. Private reference attachments and client-facing conversations with separate data-access checks.
3. Artist quote delivery, customer explicit acceptance, order creation, then payment verification/integration.
4. Replace demo Design Lab and provisional commission previews with approved public launch UI.
