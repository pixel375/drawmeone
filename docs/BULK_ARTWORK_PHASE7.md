# Draw Me One — v1.7 secure bulk artwork library

Updated 2026-11-23. Implemented as **private Hostinger storage + MySQL metadata + validated public image service**. This means artist drawings are NOT committed to the public GitHub repository, even though the repo is public and Hostinger continues auto-deploying code.

## Features

- Artist's Desk → **Artwork**: select 1 or 100+ real artwork images and upload sequentially with per-file results. No required image titles, tags, descriptions, captions, or per-file forms.
- JPEG, PNG and WebP input only. GIF, SVG, PDF, HEIC, executable files and unknown formats are rejected by both MIME inspection and image decoding.
- Per-file limits: at most 8 MiB, width and height 100–6500 pixels, 16 megapixels maximum; 2,000 total artworks per installation initially.
- All uploads must originate from an authenticated, active admin on HTTPS with correct origin, CSRF and `DMO_ARTWORK_WRITES_ENABLED=1`. No customer or unauthenticated upload endpoint.
- Image processing: PHP GD decodes source and re-encodes both `thumb.webp` (max 840 pixels long edge) and `display.webp` (max 1900 pixels long edge). The raw uploaded image is NOT stored. This removes EXIF metadata and ensures the public response is genuine WebP instead of user-supplied HTML/SVG.
- Files live in the private folder `dirname(PRIVATE_ENV_PATH)/drawmeone-artworks/{random-id}/{thumb|display}.webp`, **outside public_html**. No filename supplied by the uploader is trusted. File identifiers and metadata live in the existing `dmo_portfolio_artworks` and `dmo_artwork_files` tables.
- Uploaded images are always `visibility='draft'` until explicitly published by the artist. Drafts can be previewed only via a verified admin session.
- Library tools: preview, publish/unpublish, featured toggle, sort order, select all displayed drafts and bulk publish; no destructive permanent deletion feature in this phase.
- Public `GET /api/portfolio.php` lists published drawings only. Public `GET /api/artwork.php?id=N&v=thumb|display` serves optimized images **only if their parent artwork is published**. Draft requests return 404. No private storage keys are exposed.
- Public gallery loads published database artworks first, then previously added local portfolio JSON. Existing clearly labeled demo vector art remains visible only when neither source has real drawings.
- All database changes have an audit record, and publishing verifies the optimized files exist before making an image public.

## Hostinger preparation / enablement

No new MySQL migration is required. The server was checked via the GitHub SSH diagnostic: **PHP GD, imagewebp and Imagick are available**. The workflow also confirmed database connectivity and PHP syntax for previous phases.

Private `.env` must continue to live outside public_html. Add this setting:

```dotenv
DMO_ARTWORK_WRITES_ENABLED=0
```

It defaults OFF if absent. When the upload API and public image routes have passed testing, set `DMO_ARTWORK_WRITES_ENABLED=1` on the private server to enable the authenticated artist's upload and publishing controls. This does NOT enable customer requests, email delivery, payments, or customer uploads.

Upload one small **real artist-owned drawing** as a private draft in a controlled test. Verify:
1. The dashboard shows a private preview and draft status;
2. The public `/api/portfolio.php` does not include it;
3. Direct public `/api/artwork.php?id=NEW_ID&v=display` returns 404;
4. Publish explicitly in the dashboard;
5. The public manifest now lists it and both thumb/display variants load;
6. Unpublish; the public manifest removes it and images return 404;
7. The preview remains available to an authenticated administrator.

Keep the upload flag OFF until those checks have passed. Never add customer reference images to this portfolio; future reference attachments must use an entirely separate private storage workflow.

## Backup and lifecycle

**Important:** These artworks are NOT in GitHub and will not be restored by cloning the repo or deploying new code. Back up **both** the private `drawmeone-artworks` directory and the related `dmo_portfolio_artworks` / `dmo_artwork_files` database records, and keep the originals separately on your computer. Never include private configuration files or customer media in a public Actions artifact. Confirm Hostinger backups include the private directory before production use.

If a PHP process or server fails during the upload, some orphan private files can remain. This phase attempts cleanup on exceptions, but filesystem/database writes are not fully atomic. A later maintenance job can scan for orphans safely; don't run blanket deletion scripts.

## Endpoints

- `GET /api/admin/artworks.php` — admin-only library metadata and flag
- `POST /api/admin/artworks.php` — admin-only visibility/featured/order action (CSRF + feature flag)
- `POST /api/admin/artwork-upload.php` — admin-only one-file multipart upload (CSRF + feature flag)
- `GET /api/admin/artwork-preview.php?id=N&v=thumb` — private authenticated image
- `GET /api/portfolio.php` — public published portfolio JSON
- `GET /api/artwork.php?id=N&v=display` — published WebP only

No account or payment features are changed.

## Pre-launch checks

- [ ] GitHub Actions PHP syntax check passes for all new files on the Hostinger PHP interpreter.
- [ ] Public portfolio endpoint returns empty when all artwork is drafts; no private metadata in responses.
- [ ] Admin library and previews reject anonymous visitors; POST rejects missing CSRF or wrong origin.
- [ ] Bad MIME/signature, too-large files, unsupported types, and image dimension limits are rejected.
- [ ] Verify upload/publish/unpublish of an actual artist-owned test image.
- [ ] Validate stored private directory permissions, metadata stripping and both image sizes.
- [ ] Check gallery mobile and desktop; make sure the 24 design demo images disappear once the first real published artwork exists.
- [ ] Arrange independent private storage backups.

**The core uploader is implemented, but end-to-end upload testing with a real drawing is not yet complete. Do not report production uploading as verified before that test.**
