# Secure delivery draft milestone

Artist-only staging for an existing eligible order, using separate private storage outside the webroot. Images are decoded and re-encoded as full-dimension high-quality WebP (not original or lossless masters), limited to 8 MB input, 16 million pixels and four drafts per order. Strict admin authentication, same-origin multipart CSRF, request/order association, rate limits, request locking, and checksum verification protect writes and downloads. Customer API does not expose draft records, URLs or bytes; no release or payment mutation exists.

Additive 004 migration prepared with fixed guarded SSH workflow, not triggered until CI confirms schema compatibility. The staging switch defaults off. Encrypted backup archive and restore validation include the separate storage root; synthetic recovery checks include a staged WebP. Draft removal and release, verified payments, final format/licensing decisions, and production migration/QA remain outstanding. No live uploads or production data changes were requested.

## Follow-up

Migration constraint names now use a unique stage004 prefix to avoid collision with the initial schema. The disposable migration is imported twice to check idempotence. Private previews and explicit audited removal revoke database access before filesystem cleanup. File corruption is rejected by the download endpoint. Recovery tests now verify restored delivery metadata against the recovered file checksum, not just presence of image bytes. Browser fixtures cover staging/removal without sending real files to Hostinger. Customer release remains unimplemented until actual payment evidence and policy requirements are designed. Production migration waits for successful disposable CI.
