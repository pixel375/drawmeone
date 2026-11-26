# Controlled launch runbook

## Current state

All five hosted-invoice suites passed on d5222f26. Additive production schema 006 was applied successfully by workflow 45544817268. All commercial switches remain closed. This pass adds invoice follow-up and consistency checks, requests a new encrypted local backup, and adds an uninterrupted HTTP commission journey starting with a verified synthetic account. Synthetic invoice redirects are not followed; there is no outside provider, charge, email or production customer. This is not evidence that real registration/CAPTCHA/SMTP or a payment provider works end-to-end.

## Stage 0 — Owner decisions and independent recovery

- Confirm real portfolio artwork and permissions, design, service scope, actual prices/currency, licensing, revisions, refunds, privacy/retention and final legal text. Publish immutable policy bundle and pin its digest only after approval.
- Select provider and receiving account. Verify that an order-specific invoice can charge exactly the agreed total once, cannot be adjusted, tipped or subscribed, and is compatible with the restricted host/path grammar. No API or webhook is connected yet.
- Configure independent encrypted backup destination and key custody; run isolated recovery outside the hosting account. Local encrypted backup alone is not enough. See [independent recovery workstation preparation](INDEPENDENT_RECOVERY_MILESTONE.md), including separate custody of DMO_APP_KEY for encrypted invoice URLs.
- Before any opening, review live readiness, integrity and backup indicators and verify deployment commit. Retain a rollback configuration outside public_html.

## Stage 1 — Controlled onboarding

Enable only the minimum approved registration/auth gates for a bounded test period. Keep intake paused and all payment/delivery switches closed. Exercise registration with an authorized test identity, real email verification, CAPTCHA, login, password reset and logout. Check both customer and admin isolation. No real customer records used as test fixtures.

## Stage 2 — Controlled request and quote

With approved policies and an eligible test account, enable reviewed request/reference/message gates. Set capacity to one and open intake only for the controlled test window. Verify submission, duplicate retry, private image access, artist review, quote acceptance and separate unpaid-order confirmation. Do not accept a live payment before terms and provider verification.

## Stage 3 — Hosted provider validation

Use the provider's test environment if available. Confirm the receiving merchant, exact amount and currency; test failed/expired payment, duplicate attempt, external closure and local suspension. A return URL or redirect is not proof of payment. Manual reconciliation requires independent provider-account verification; a real transaction, if needed, requires owner authorization. Never enter raw card data on DrawMeOne. Automated verification requires provider-specific authenticated API/webhook contract, signature replay defense, account binding, unique provider event/transaction IDs, amount/currency/status checks, dispute handling, retry and audit design. Do not activate generic unverified webhooks.

## Stage 4 — Delivery and operations

Test production transition, proof, included revision, final release and download with two accounts. Verify payment hold denies future download and restore only after settlement. Review privacy workflow, retention inventory, invoice follow-up, backups and integrity monitoring. Confirm staff can handle exceptions without deleting records or issuing unapproved refunds.

## Stage 5 — Public opening and rollback

Owner approves readiness and explicitly opens selected gates. Intake starts paused if the capacity feature is enabled. Initially use a conservative capacity. If an incident occurs, pause intake first, then disable affected commercial action switches while preserving existing customer history where possible. Do not delete existing data or rotate the application key as a rollback. Escalate provider closure separately; local disabling cannot cancel external invoices. Review active orders and verified payments before any policy or key change. Retain test evidence and document the decision to open.

## Configuration evidence and release verification

Artist's Desk now exposes six stage summaries and a downloadable configuration-only JSON report. CLI equivalent: `php app/cli-launch-readiness.php check` (safe fixed summaries) or `php app/cli-launch-readiness.php json` (same stage report). A zero exit status means the report was generated, not that the site is authorized to open. Actual owner/provider/onboarding/recovery evidence is not recorded or inferred by this report. Record and review those results separately. The report contains aggregate published artwork/package counts and intake state, never customer identities or secrets. Live diagnostics now wait for all expected public asset markers together within a bounded retry window before checking detailed assets, to account for auto-deploy lag. No private flags or operational records changed.


## Private configuration review before opening

Run the read-only `php app/cli-config-review.php check` (or `json`) and inspect reported fixed issue codes. It checks known boolean syntax, contradictory prerequisite switches, current authentication/mail/policy/checkout configuration, duplicate boolean definitions and private configuration file permissions. It never prints raw configuration values, paths or credentials and does not modify configuration. An issue may be intentional during staged preparation; resolve or document it before the relevant stage. A successful report is not authorization, nor evidence of SMTP delivery, provider verification, off-site recovery or customer onboarding. The policy draft has been updated to reflect implemented features but remains unapproved.

## Runtime and public-opening checklist

Image-processing readiness now checks the PHP functions used for accepted image formats, optimization, MIME inspection and orientation transforms. EXIF support is reported as an optional capability; its absence does not block JPEG upload. This is only a capability check, not proof of successful storage or upload. Public opening configuration now includes core account, pricing, intake, quote, order, fulfillment, privacy and configuration-review prerequisites instead of only portfolio/policy/backup checks. Optional features and payment-provider choice remain subject to operator review. No readiness report authorizes launch. Production CLI diagnostics on 2026-11-25 confirmed EXIF support and matched deployed asset digests in run 45692721176.


Session XX: A failed or in-progress readiness refresh clears previously displayed launch, integrity and retention cards, disables stale intake edits, and marks the information unverified instead of showing previous results as current. The configuration-only browser export now emits valid JSON (actual trailing newline); automated browser coverage parses the downloaded report and verifies its explicit non-authorization flag. Readiness language distinguishes configured checks from owner approval.
