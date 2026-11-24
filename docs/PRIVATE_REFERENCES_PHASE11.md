# Draw Me One v2.1 — private customer reference images

Implemented November 23, 2026. **Customer uploads disabled** until owner-reviewed policy and authentication QA.

## Architecture
- An additive `database/003_private_references.sql` creates `dmo_request_attachments` with indexed request ownership, random non-public `storage_key`, minimal dimensions/byte metadata, uploader ID and timestamp. No private image data enters GitHub.
- `app/cli-migrate-references.php apply` is CLI-only, permits exactly the committed SQL migration, checks customer services are closed and verifies the expected schema. The fixed `hostinger-reference-migrate.yml` workflow executes it using existing pinned GitHub/Hostinger SSH credentials without arbitrary shell input. No existing rows/orders are modified by design.
- Customer `POST /api/request-attachment.php`: active verified customer, same origin, HTTPS, CSRF header, owned request reference, explicit flag/policy checks, multipart JPEG/PNG/WebP, 4 MB/source, maximum 16 megapixels, 8 references/request, 12 uploads/customer/hour. Uploaded bytes are decoded with GD, re-encoded to independent thumb and display WebP files, stripping metadata/embedded scripts. Source names and original uploads are never stored.
- Files reside outside `public_html` under a separate private reference directory with restrictive permissions and randomized names. API never returns file keys, folders or filenames.
- `GET /api/reference.php?id=…&variant=thumb|display` permits only verified current artist/admin or the owning customer, and responds `private, no-store`. Unauthorized or cross-customer access is denied. It never serves raw originals/SVG/scripts.
- Admin request review and customer-owned request detail return **only** integer IDs, pixel sizes, bytes and created timestamps; their respective UIs use protected image URLs. Existing requests without references continue to work even before migration.

## Private activation guards
```dotenv
DMO_AUTH_ENABLED=0
DMO_REFERENCES_ENABLED=0
DMO_PRIVACY_PUBLISHED=0
DMO_REQUEST_TERMS_APPROVED=0
```
The upload feature also requires the installed migration. New intake `DMO_REQUESTS_ENABLED` is independent; a registered owner of an existing open request can add references when uploads are deliberately enabled, without reopening public submissions.

## Prelaunch verification checklist
- [x] Apply migration through pinned SSH on Hostinger; Action run 45307970833 completed successfully, confirming `private_reference_schema_ready=yes` and `customer_service_unchanged=closed`.
- [ ] Create a controlled verified customer test account, upload JPEG/PNG/WebP cases and confirm proper orientation and private thumbnails.
- [ ] Test oversized/corrupt/SVG payload rejection, file count cap, CSRF/origin, rate limiting and blocked MIME/dimensions.
- [ ] Attempt cross-customer and unauthenticated access to both variants; verify they return denial and do not expose the file.
- [ ] Validate private directory backups **independently** of MySQL; restore a test file and its DB association before relying on real customers.
- [ ] Publish approved policies and decide retention/deletion/consent before enabling uploads.

No public upload or customer signup is activated by this phase. The first true reference uploads must only use user-owned or licensed images.


## Deletion support

`POST /api/reference-delete.php` requires a verified owning customer, HTTPS/same-origin JSON, CSRF token, an exact request reference and integer attachment ID, plus enabled policy and upload gates. It denies cross-customer deletion and reads. On success, the attachment database row is removed before private WebP files are unlinked. If file cleanup cannot finish, no authenticated endpoint can retrieve the orphan; the API reports `storage_cleanup_complete=false` and logs a sanitized warning. Orphan cleanup/retention still needs an operational pass before launch.
