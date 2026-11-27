# Draw Me One V2 — owner launch checklist

The portfolio is already live; commissions remain closed. All 14 independent implementation items passed as of November 27, 2026. This is a practical checklist for the artist and site owner, not another feature roadmap. Do not use historical V1 launch instructions for the simplified site.

## 1. Portfolio and Artist Desk (OWNER-01)
- Open [the live site](https://drawmeone.com/) and [Artist Desk](https://drawmeone.com/studio-admin/index.php) on a phone and computer using the existing account.
- Upload a small batch of genuine, approved drawings. Verify that private drafts are invisible publicly. Publish selected drawings, check their full-image view, and verify that Make private removes a drawing from the gallery and its detail URL. Republish only approved art.
- Try keyboard navigation, the theme switch, image descriptions, and the four sections. Report specific defects and screenshots, avoiding credentials or personal enquiry data.
- The latest production diagnostic reported zero published drawings; there is no demo art.

## 2. Artist content and payments (OWNER-02)
- Confirm the headline and introduction, public support address (currently support@drawmeone.com), optional Instagram and TikTok profiles, and default theme.
- Enter actual accepted payment method names and optional starting price in Payments. Leave unapproved fields blank. Agree prices and share payment instructions privately; no checkout or card data.
- Confirm the public result. Never share passwords or bank credentials in chat or GitHub.

## 3. Privacy and drawing rights (OWNER-03)
- Review the [privacy notice](https://drawmeone.com/privacy.php), permitted artwork/reference use, how requests and email are handled, retention duration, deletion requests, and any pricing/cancellation/refund terms before accepting payment.
- Archiving an enquiry is not deletion. No retention period or legal promises have been approved by the owner; do not invent them. Keep sensitive reference material out of the initial form.

## 4. Independent recovery (OWNER-04)
- Choose and authorize an independent private encrypted-backup destination (e.g. an owner-controlled cloud storage account or separate storage provider). Choose a distinct protected location for recovery key custody outside both Hostinger and the archive destination.
- Existing [issue #1](https://github.com/pixel375/drawmeone/issues/1) and [recovery instructions](INDEPENDENT_RECOVERY_MILESTONE.md) describe the technical path. Preserve the original application key for archived V1 data separately, too. Do not send keys through chat, email, GitHub files, issues or logs.
- Configure only approved least-privilege credentials in the GitHub environment, run the existing manual-only offsite workflow, verify ciphertext readback and independently restore into isolated infrastructure with outbound integrations disabled.
- Approve scheduling and independent failure monitoring only after successful recovery. Local backup readiness and synthetic restore tests are not independent custody evidence.

## 5. Controlled mail and accessibility pilot (OWNER-05)
- Explicitly authorize one fixed SMTP diagnostic to the recorded verified admin, then confirm actual inbox receipt. The existing SMTP workflow sends one message; SMTP acceptance alone is not proof of receipt.
- Separately authorize a controlled end-to-end guest enquiry pilot, including any temporary public-intake exposure. Do not open public intake just to run the test without explicit approval; agree on a safe pilot method first. Confirm real CAPTCHA, private inbox persistence, notice delivery and email reply. Close intake again unless the final opening has been approved.
- Review real devices and manual keyboard/assistive-technology behavior. Automated axe checks found no violations in nine tested states but are not a full accessibility certification. Use only approved test details, no real payment.

## 6. Open commissions (OWNER-06)
Only after the preceding reviews and blockers have been addressed, explicitly authorize opening. In Artist Desk > Requests, enable Accepting commissions. Verify the public form and private inbox. Switch off whenever unavailable. Do not revive customer accounts, orders, quotes or payment management.

Record decisions and verification evidence in the master checklist without private contact data, secret values or real customer content. If a step fails, keep the relevant item unchecked and commission intake closed until resolved.
