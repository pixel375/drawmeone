# Artist Desk — starting guide and owner decisions

**Autonomous phase verified complete — 2026-11-26.** All 47 AUTO tasks checked; 21 owner decisions, 24 dependent tasks and 4 optional tasks remain open. The next action is your manual visual review, not public launch. The canonical [LAUNCH_TODO.md](LAUNCH_TODO.md) remains authoritative. Do not send credentials, private drawings or customer data to GitHub issues.

Verified cumulative application release: [33249c6](https://github.com/pixel375/drawmeone/commit/33249c6567e8c50eed565c2af414f65bbc8f77f2), all five workflows passed ([source](https://github.com/pixel375/drawmeone/actions/runs/45911525634), [DB](https://github.com/pixel375/drawmeone/actions/runs/45911525944), [Chromium](https://github.com/pixel375/drawmeone/actions/runs/45911525728), [Firefox/WebKit](https://github.com/pixel375/drawmeone/actions/runs/45911525647), [Hostinger](https://github.com/pixel375/drawmeone/actions/runs/45911525720)); [live indexing guard](https://github.com/pixel375/drawmeone/actions/runs/45911557935) passed. [Latest local encrypted backup](https://github.com/pixel375/drawmeone/actions/runs/45876093653) and [freshness check](https://github.com/pixel375/drawmeone/actions/runs/45909696394) passed; independent offsite recovery remains unconfigured.

Start with visual feedback only. You do not need to make every legal, provider or commercial decision before sending the requested UI/feature changes. Review existing private drafts in Artist Desk; public viewer testing with actual artwork requires approved publication, so do not publish just to complete a visual review. Public gallery empty is expected (zero published drawings). Registration/intake/quote/order gates remain closed. The launch checklist is not permission to open.

## First visual review (when autonomous work is verified)

- Open https://drawmeone.com/ on desktop and phone. Review Studio, dark/light mode, logo, gallery layout (Scroll preferred), hero/contact copy, navigation, mobile menu and complete-image viewer. Check both portrait and landscape images without cropping. Record section, device/browser, observed issue and requested outcome; use a screenshot with no private content if helpful.
- Sign into https://drawmeone.com/studio-admin/index.php with the existing administrator account. Never share the password. Review Overview, Requests, Messages, Finance, Customers, Artwork, Packages and Settings. Review the design draft/preview before applying anything publicly. No MFA is required.
- Prepare original artist-owned/authorized artwork. Use bulk upload in Artwork; text is optional. Review private drafts, thumbnails, full images, order and featured selection. Publish only approved drawings when explicitly ready. The site's public portfolio is empty until the artist publishes original works; do not use demo art as genuine artwork.
- In Packages, prepare actual tiers/extras with currency, indicative estimates, included scope and revision rules. Quotes are separately itemized and customer accepted. Do not approve/publish prices or change intake before business decisions and policy review.
- Check the configuration-only readiness export. It does not certify legal approval, recovery, live mail, customer onboarding, external settlement or permission to open.

## Daily operating sequence (after approved controlled launch)

1. Review Overview: intake capacity, requests needing review, quote follow-up, orders/deadlines, payment evidence, invoice closure, holds and privacy cases. Pause intake when capacity or integrity is uncertain.
2. Review submitted briefs/references and private messages; clarify scope. Draft a quote with exact currency, itemized total, included revisions, validity and agreed scope. Release only after review; verify customer acceptance. Confirmation creates an unpaid order, not a payment.
3. Use the approved provider procedure in PAYMENT_OPERATIONS_GUIDE.md. Independently reconcile actual settled evidence and external invoice closure; do not start paid work based on a screenshot or redirect.
4. Update production deadlines and status, stage private proof, release for review, review included revisions. Confirm final artwork against approved proof and payment status. Release only the intended file. Never expose customer artwork in the public portfolio without separate permission.
5. Respond to support/privacy requests in the approved process. Export is scoped structured records, not a complete legal data response. Record review/outcome; no automated deletion or correction. Preserve disputes, agreements and evidence; do not use retention preview as authorization.
6. Review backup freshness and integrity status. Escalate missing/invalid files, uncertain payment evidence, failed email or security activity using INCIDENT_AND_MAINTENANCE_RUNBOOK.md. Do not retry a financial external action blindly.

## Consolidated owner decisions (all deferred until autonomous completion)

| Checklist IDs | Decision / input | Result to provide |
|---|---|---|
| ART-01/02 | Original art and rights; initial 6–12+ drawings, ordering/featured/watermark | Approved selection and permission |
| DES-06/08, OPEN-04 | Final appearance/copy/logo/gallery/default theme; manual visual feedback | Specific changes, final approval |
| CAT-04/05, FUL-04 | Service scope, tiers/extras, currency/prices, revision/turnaround/capacity, delivery/proof quality | Approved catalog and process |
| LEG-03–06, PRV-04 | Legal operator/jurisdiction, support/privacy inbox, copyright/license/age/content, refund/payment, retention/legal holds, actual providers, final terms | Reviewed and approved text/policies; obtain qualified advice as needed |
| BKP-04/05 | Independent private storage destination, separate recovery key AND original app key custody | Secure mechanism and permission; never paste keys into chat/issues |
| PAY-04/05 | Verified merchant/provider, currencies/fees/countries, manual vs automatic, payment/deposit/refund timing, any authorized test transaction | Provider choice and explicit test authorization |
| ACC-04 | Bounded production pilot, approved test identities/inboxes, support access | Authorization after policies and recovery prerequisites |
| QA-04 | Actual phone/desktop browsers, Safari/Edge, screen readers, 200%+ zoom | Manual visual/accessibility results or authorized tester |
| SEO-03 | Public art/copy/social preview and Search Console ownership | Separate indexing approval only when ready |
| OPEN-07 | Staged public opening, conservative capacity, separate indexing authorization | Explicit approval after completed pilots and final release |

No owner item is checked by this document. Subsequent dependent work: ART-05, DES-07/09, CAT-06, LEG-07, BKP-06/07/08, ACC-05/06, REQ-04/05, PAY-06/07/08, FUL-03, PRV-05/06, SEC-07, QA-05, SEO-04, OPEN-05/06/08. Refer to TODO for exact dependencies. Provider-specific automation and optional features remain deferred; no automatic live charges, deposit/installment or partial refund support is implied.

## Evidence template for each authorized stage

| Field | Record |
|---|---|
| UTC date / release SHA | [ ] |
| Stage and owner authorization reference | [ ] |
| Test environment / anonymized test identity | [ ] |
| Expected and observed result | [ ] |
| Five workflow run IDs / exact live verification | [ ] |
| Real external checks (mail/CAPTCHA/provider/recovery as applicable) | [ ] |
| Privacy / role isolation / backup / integrity | [ ] |
| Gates enabled temporarily / restored | [ ] |
| Open issues, responsible reviewer and decision | [ ] |

Never record raw credentials, customer briefs, invoice URLs, card details, recovery keys or personally identifying evidence. A configuration-only report or synthetic pass is not authorization.

## Rollback checklist

Pause intake; restrict affected writes under authorized access; preserve history, accepted agreement pins, financial evidence, original app key and existing backups; separately close or reconcile external invoices. Verify compatible prior release and schema before rollback. Restore only in isolation until a reviewed recovery plan authorizes repair. Reverify exact release and all affected workflow/owner evidence before reopening. See [controlled launch](CONTROLLED_LAUNCH_RUNBOOK.md), [incident response](INCIDENT_AND_MAINTENANCE_RUNBOOK.md), [payments](PAYMENT_OPERATIONS_GUIDE.md), [independent recovery](INDEPENDENT_RECOVERY_MILESTONE.md), [retention](RETENTION_REVIEW_MILESTONE.md) and [search launch](SEARCH_LAUNCH_CHECKLIST.md).
