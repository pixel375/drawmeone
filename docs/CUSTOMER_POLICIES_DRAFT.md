# Draw Me One — Customer Terms & Privacy Draft (NOT APPROVED)

Drafted November 23, 2026. **For business-owner and legal review only.**
This is not published as the site's binding Terms of Service or Privacy Policy.
Do not enable `DMO_REQUEST_TERMS_APPROVED`, `DMO_PRIVACY_PUBLISHED`
or public commission intake based on this draft alone.

## 1. Commission terms — proposed customer-facing text

**Commission status:** Sending a request does not create an order, bind either
party to a price or delivery date, or authorize a charge. The artist reviews
the description and may decline a request. An order exists only after the
customer receives a final quote, affirmatively accepts the agreed scope and
price, and any required payment arrangement is confirmed.

**Price and currency:** Estimates on the site are indicative and may change
based on characters, complexity, backgrounds, commercial usage and deadline.
The quote will state the applicable currency, included revisions, scope,
delivery format, any deposit, and total price before acceptance.

**Creative brief and references:** Customers must submit artwork requests
they are authorized to share. Private reference images will be introduced
only after secure upload controls are built. Do not submit payment card numbers,
government identifiers, medical details or other sensitive information in
request text.

**Review and revisions:** The artist and customer should confirm the number
of included revisions and the cost of additional changes in the accepted
quote. Work outside the agreed scope requires a new agreement.

**Artist discretion and prohibited requests:** The artist can refuse work
that is unlawful, infringes third-party rights, or violates published
content rules. **Owner to define content boundaries clearly.**

**Ownership and permitted use:** Unless a signed quote explicitly provides
otherwise, original artwork rights and customer usage permissions must be
identified in the accepted quote. **Owner must choose a clear default
personal-use vs commercial-use licence and whether portfolio display is
allowed.** Do not assume automatic copyright assignment.

**Timelines and communication:** Estimated dates are not guarantees until
confirmed in the accepted quote. Delays, progress updates, delivery format
and change requests should be communicated through the agreed contact method.

**Payment, cancellation and refunds:** **Owner and counsel must complete
this section before accepting any money.** Specify when a deposit is due,
which payment processors are supported, cancellations before/after work
begins, partial-work fees, refunds, chargebacks, and any non-waivable
consumer rights. The current website does not automatically charge or
process payments.

**Disputes and legal scope:** **Owner to identify operating legal entity,
jurisdiction, legal contact and governing law, subject to non-waivable
consumer protection rules.**

## 2. Privacy policy — proposed customer-facing text

**Operator/contact:** Draw Me One, operated by
**[insert legal operator/business name]**.
Privacy contact: **[insert verified contact address at drawmeone.com]**.
Jurisdiction of operation: **[confirm]**.

**Data collected:**
- Account registration: display name, email, salted/hashed password and
  email-verification state.
- Security: session identifiers and abuse/rate-limit markers; raw passwords
  are not stored in the database.
- Commission requests: descriptions, selected options, deadlines, preferred
  contact and revision/quote history when available.
- Messaging: correspondence between the artist and customer when enabled.
- Artwork: approved portfolio uploads and, in a later phase, any private
  references deliberately submitted by customers.

**Purposes:** Verify accounts, handle commission inquiries, protect the
service from abuse, communicate about orders, and deliver artwork when
ordered. **Confirm whether optional marketing or analytics are used before
publication**; the current workflow should not assume consent.

**Service providers:** The website is hosted through Hostinger, uses
Cloudflare Turnstile for security challenges, and may use an email provider
to deliver verification/recovery messages. **Verify actual hosting,
mail provider, international transfers and retention terms** before
publishing this wording. GitHub hosts source code, not customer records or
uploaded private artwork.

**Cookies/session storage:** The site uses essential secure session cookies
for login and may store a temporary random request-retry identifier in the
browser to avoid accidental duplicate submissions. Details of optional
analytics/cookies should be added if implemented.

**Security:** Application passwords are hashed, private data routes require
authorization, and customer information is kept outside public source code.
No internet service can guarantee absolute security.

**Retention and deletion:** **Owner must choose retention periods** for
unaccepted inquiries, completed orders, security logs, legal/accounting
records and backups, along with deletion, export and correction procedures.
Do not promise instant deletion where obligations or backup constraints apply.

**Access and corrections:** Customers may request information about data held
about them, corrections and deletion subject to applicable law and business
record requirements. Provide a verified contact method and response process.

**Children and restricted requests:** **Owner must decide minimum age and
parental consent requirements** before opening registrations. Collect no
unnecessary sensitive data.

**Changes:** Publish a version date, disclose material privacy changes, and
record the version acknowledged at submission.

## 3. Approval checklist — DO NOT SKIP

- [ ] Confirm the legal name of the business/operator and a reachable support
      and privacy contact; never invent these for the public website.
- [ ] Confirm operating jurisdiction and any applicable consumer cancellation,
      tax, data privacy and electronic commerce requirements.
- [ ] Decide content boundaries, restrictions involving fan art, and age policy.
- [ ] Approve prices in CAD/USD, revision allowances, deposits, refunds and
      handling of incomplete or cancelled work.
- [ ] Set personal/commercial rights, copyright ownership and permission to
      display finished commissions in the public portfolio.
- [ ] Approve retention schedules, data export/deletion process, analytics
      disclosures and third-party data processors.
- [ ] Publish final, reviewed Terms and Privacy pages with permanent URLs.
- [ ] Record a unique revision identifier in private `.env`
      (`DMO_REQUEST_POLICY_VERSION`).
- [ ] Only then consider enabling `DMO_REQUEST_TERMS_APPROVED=1`,
      `DMO_PRIVACY_PUBLISHED=1`, and finally `DMO_REQUESTS_ENABLED=1`.

This text is a draft for discussion, not legal advice and not a substitute for
reviewing laws that apply to actual customers and business operations.
