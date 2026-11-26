# DrawMeOne — payment operations and provider decision guide

**Preparation only. No provider selected, live payment authorized, or activation implied.** See the [master launch TODO](LAUNCH_TODO.md), [owner policy decisions](OWNER_POLICY_DECISIONS.md), [hosted checkout milestone](HOSTED_CHECKOUT_MILESTONE.md) and [controlled launch runbook](CONTROLLED_LAUNCH_RUNBOOK.md).

## Provider selection (owner review, deferred)

| Requirement | Evidence required before enabling |
|---|---|
| Merchant eligibility | Actual country/business eligibility, verified account owner, receiving account and payout currency |
| Hosted checkout | Unique fixed-total, single-use invoice; exact currency; no adjustable amount, tip, subscription or repeat payment |
| URL compatibility | Canonical HTTPS provider hostname and fixed path prefix; opaque ASCII path segments only. No query, fragments, alternate port, encoded characters or credentials. If incompatible, leave checkout disabled pending a specific reviewed integration. |
| Fees and settlement | Provider fees, exchange/payout costs, settlement timing, refunds and dispute procedures; no invented rate or estimate |
| Verification | Provider's authenticated dashboard or authenticated API; immutable transaction ID, receiving merchant, exact amount/currency, final successful/settled status |
| Failure handling | Confirm invoice expiry/closure, unsuccessful/pending/duplicate payments and refunds externally. Local link expiry is not external closure. |
| Privacy/security | Merchant account access, provider processing disclosures, support obligations, separate credential custody |
| Automation | Optional only after a provider is selected: signed event verification, replay defense, transaction uniqueness, merchant binding, retries and dispute handling. No generic webhook. |

Owner decisions remain blank: provider **[TBD]**, legal operator/merchant **[TBD]**, payout account/currency **[TBD]**, supported regions **[TBD]**, fees **[TBD]**, approved refund policy **[TBD]**, provider test evidence **[TBD]**. Keep confidential values outside repository and audit notes.

## Artist workflow (manual, after authorization)

1. Verify accepted quote and confirmed order in the private Artist Desk. Check itemization, full amount in minor units and currency. The order initially remains unpaid/unverified.
2. In the verified receiving account, create a fixed-total single-use invoice for that exact order. Confirm merchant, customer, scope and amount. Never paste credentials, card data, receipt images or raw transaction details into messages. Store the unique invoice URL only through the protected checkout form. Do not use reusable payment links.
3. Record pending evidence only from an independently reviewed provider/bank record. A customer screenshot, success redirect, email or claim is not payment proof. Use the stable provider transaction reference and correct method. The website stores only a keyed fingerprint; it cannot independently confirm money received.
4. Pending evidence suspends the local invoice permanently. Independently check merchant/account, transaction ID, full amount/currency and completed settlement; confirm receipt explicitly in Artist Desk using the same reference. Reject unsuccessful evidence; rejected references cannot be reused. Do not accept partial/overpaid/mixed-currency evidence as the full quoted amount.
5. Confirm external closure/settlement of the invoice before revoking it locally. A replacement requires a new unused provider invoice, the correct latest local reference and documented closure. Seven-day local expiry is not provider expiry. If evidence is rejected, the old invoice stays unavailable; do not re-expose it.
6. Only verified and internally consistent evidence enables paid production and protected artwork. Local finance views are reconciliation records, not provider statements, taxable revenue, or a bank balance. Match independently and investigate discrepancies; do not change quote totals or payment records directly.

## Exceptions and uncertain outcomes

- If a write response is lost, refresh the request and finance history. Never repeat an external payment, refund or invoice creation merely because the site failed to confirm a write.
- If a transaction is pending, failed, expired, wrong-currency, partial or duplicated, do not mark paid. Review externally and reject invalid pending evidence when appropriate. Never delete audit history.
- For a dispute, reversal or reconciliation error, record a payment access hold after external review. This denies future protected access; it cannot retrieve previously downloaded files.
- Restore access only once external settlement and internal evidence are independently consistent. A full external refund is executed separately by an authorized operator; the local refund action merely records it. Full refund is terminal for that order; deposits, instalments, partial refunds and automatic charges are unsupported.
- If there are inconsistent records, duplicate evidence or multiple unclosed invoices, stop intake/payment for the affected order, retain records, inspect the provider and integrity report, and escalate. Do not repair production data directly without a reviewed recovery plan.

## Controlled pilot record

Record date, anonymized order/reference, provider sandbox vs authorized live status, expected amount/currency, merchant verification (yes/no), success/failure/expiry/duplicate/closure/refund outcomes, protected access checks, and remaining issues. Do not log raw invoice URLs, payment IDs, credentials or PII. A successful synthetic CI run is not evidence of provider settlement.
