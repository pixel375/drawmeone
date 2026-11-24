# Draw Me One v2.0 — private onsite conversation foundation

Implemented November 23, 2026. **Not open to customers**.

## What is included
- Customer-only `POST /api/request-message.php`: verified customer account, strict origin/HTTPS and JSON/CSRF, request owner match using an indexed reference, 2–3000-byte plain-text messages, serialized request row, rate limits (20/IP/hour and 12/customer/hour), 500-message hard thread cap, audit IDs only.
- Admin `POST /api/admin/request-actions.php` has the distinct `send_customer_message` action. Messages are `is_internal=0`, visible to their request owner. Existing `add_private_note` remains `is_internal=1` and **must never** be sent or shown to customers.
- Customer detail returns only non-internal messages. Artist detail retains internal notes and customer-visible conversation, with sender role and separate clearly labeled compose boxes.
- A closed/cancelled/completed request is readable but cannot receive new messages; all requests continue to be ownership-checked. No customer quote, order/payment, outbound email or unsolicited bulk messaging is created.

## Private gates
```dotenv
DMO_AUTH_ENABLED=0
DMO_CUSTOMER_MESSAGING_ENABLED=0
DMO_PRIVACY_PUBLISHED=0
DMO_REQUEST_TERMS_APPROVED=0
DMO_REQUEST_ADMIN_WRITES_ENABLED=0
```
No flag is changed by deployment. Messaging is possible only with customer auth, explicit messaging switch, approved terms and published privacy. Artist replies additionally require the admin private-write switch. Opening public registration or commission submissions is **not required** to read existing requests.

## Tests and safeguards
- GitHub Actions source-quality checks lint all PHP and JavaScript on main.
- Live pinned Hostinger SSH diagnostic lints the new files; verifies `customer_messaging_flag=closed`, `http_customer-message=503` while auth is disabled and `http_admin-mail=401` without admin.
- No messages were submitted to real customers or created during automated tests.
- Still required before activation: controlled end-to-end signup, ownership isolation, rate-limit, token/recovery, CSRF rejection, both compose forms and closed-request tests; published owner-approved terms/privacy; full backup strategy.
