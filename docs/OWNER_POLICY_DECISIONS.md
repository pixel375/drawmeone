# DrawMeOne — Owner policy decision worksheet (DEFERRED)

**Unapproved working document.** Complete after the autonomous TODO backlog, with appropriate professional review. Do not publish this file, invent answers, or treat the checklist as legal advice. See [customer policy draft](CUSTOMER_POLICIES_DRAFT.md), [launch TODO](LAUNCH_TODO.md), and [artist catalog setup](ARTIST_CATALOG_SETUP.md).

## Operator, audience and sales (LEG-03)

| Decision | Owner-approved answer |
|---|---|
| Legal operator name, trade name, business address if required | **[TBD]** |
| Country/province and intended customer jurisdictions | **[TBD]** |
| Verified support and privacy email, response responsibility | **[TBD]** |
| Age restrictions, eligibility and consent process | **[TBD]** |
| Tax, consumer disclosures, cancellation and accounting review | **[TBD]** |
| Actual pricing currency, included taxes/fees, permitted payment regions | **[TBD]** |

## Art and scope (CAT-04/05, LEG-04)

| Decision | Owner-approved answer |
|---|---|
| Allowed subjects, content boundaries, fan-art and third-party rights | **[TBD]** |
| Reference image permission and prohibited sensitive material | **[TBD]** |
| Copyright ownership and personal/commercial licence defaults | **[TBD]** |
| Consent for public portfolio display and attribution | **[TBD]** |
| Included revisions, extra work, turnaround and delivery formats | **[TBD]** |
| Scope changes and cancellation before work begins | **[TBD]** |

## Payment and exceptions (LEG-05, PAY-04/05)

| Decision | Owner-approved answer |
|---|---|
| Receiving merchant, hosted payment provider and accepted currency | **[TBD]** |
| Full-payment timing and order confirmation | **[TBD]** |
| Cancellation after work starts, full refunds and dispute handling | **[TBD]** |
| Any deposits, partial refunds or instalments | **[TBD — not implemented; separate feature approval required]** |
| Customer communication for late delivery, payment hold or dispute | **[TBD]** |

Do not promise unsupported features. Current flow: request → quote → explicit customer acceptance → artist order confirmation → independently reconciled external payment → production and delivery. No card data collected by DrawMeOne. A refund record documents an external full refund, not an automatic transfer.

## Privacy and retention (LEG-05, PRV-04)

| Decision | Owner-approved answer |
|---|---|
| Hosting, email, CAPTCHA, fonts and payment provider disclosures; locations and transfer terms | **[TBD]** |
| Account, session, abuse/security log retention | **[TBD]** |
| Unaccepted requests/messages/private reference retention | **[TBD]** |
| Completed orders, proofs, revisions, delivery files and accounting retention | **[TBD]** |
| Policy/agreement receipts and legal hold exceptions | **[TBD]** |
| Local/offsite encrypted backup expiry and restoration procedures | **[TBD]** |
| Access, export, correction/deletion and complaint handling; applicable deadlines | **[TBD]** |
| Appearance preference storage, analytics/marketing if ever enabled | **[TBD]** |

The current self-service export is bounded to customer-visible structured records; media and other data may require individual review. Privacy cases are manually reviewed, and retention reporting is non-destructive. Never promise instant or universal deletion. Confirm actual vendors and legal obligations rather than copying assumptions into the final policy.

## Controlled publication (LEG-06/07)

- [ ] Record owner/legal reviewer sign-off, final wording, effective UTC date and unique version. Keep draft outside the approved bundle directory until approval.
- [ ] Prepare immutable plain-text JSON with exactly version, approved, effective_date, terms, privacy. Only set approved=true after explicit authorization. Preserve old published bytes; never overwrite an existing version.
- [ ] Hash exact file bytes (SHA-256), confirm configured current version and digest; keep previously approved continuation pins where reviewed. A changed digest requires a new version/review. Never expose unrelated configuration or keys.
- [ ] Verify public JSON and HTML match the approved bytes, wrong digests/unknown versions fail closed, print version/digest/URL, and historical agreements continue only under validated pins. Test actual acknowledgement and quote/order history with authorized controlled identities.
- [ ] Separately authorize the applicable commercial switches. Policy publication alone does not authorize registration, requests, payments or public opening.

Review record: **[reviewer / date / version / evidence / remaining limitations]**. All placeholders are intentionally unanswered; no owner action requested during autonomous implementation.
