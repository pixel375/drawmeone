# Adding artwork before the admin dashboard exists

Only use actual drawings supplied and approved by the artist. Never add stock art or AI-generated drawings as portfolio work.

1. Export a clean high-quality, sRGB **WebP, AVIF, PNG, or JPEG** image for each work. Keep private full-resolution originals somewhere safe. Aim for 1400-2200px along the longest edge as a starting point; measure visual quality and page speed.
2. Name each file descriptively and in lowercase, e.g. `assets/portfolio/ink-floral-portrait.webp`. Do not include personal names or private client information in URLs without consent.
3. Until we build the private admin dashboard, upload the file to `assets/portfolio/` in GitHub and add its path to `data/portfolio.json`.
4. **No title, description, caption, or alt text needs to be typed.** The gallery reads a simple `works` array. Example (NOT a real drawing; **do not paste into production until the corresponding artist-approved file exists**):

```json
{
  "works": [
    {
      "src": "assets/portfolio/ink-floral-portrait.webp"
    }
  ]
}
```

The public website absolutely **can and should have text**: navigation, commission information, calls to action, contact details and SEO-supporting content. The portfolio will present the art cleanly and prominently. Title and description fields are **not required** for an upload. An optional `alt` field supports more specific accessibility and search descriptions, but if omitted the current gallery uses a generic accessible fallback. The future dashboard will generate technical metadata automatically and may offer an optional review/edit feature (never block upload on it). Avoid metadata that reveals private client details.

For now, the static page loads the JSON with JavaScript. Once the PHP+MySQL dashboard is available, migrate to server-rendered HTML `<img>` elements, optimised derivatives, per-work pages, and automatically generated sitemaps. This is important for reliable SEO across search engines.

Do not upload private reference images or confidential commission messages into the public GitHub repository or public hosting folder. The future admin must enforce file validation and private access.

## Future admin bulk-upload UX (must-have)

- Select or drag-and-drop 1–100+ drawings at once; no per-image form fields.
- The system validates genuine image formats, assigns safe unique filenames, extracts dimensions, creates several optimized derivatives, strips private EXIF data, and automatically creates database records.
- Show a progress queue with thumbnail previews, per-file error/retry, duplicate detection, publish/draft toggle, and a single **Publish all** action.
- Titles, captions, descriptions, tags, and categories are optional. If needed, sensible descriptions for accessibility/SEO can be proposed from the image or an internal automatic classifier, but publishing must not require typing or accepting them.
- Reorder, hide, feature, or delete artwork later; publish dates and image URLs are managed automatically.
- Maintain artist/client consent for portfolio publishing. Private uploads for commission references are separate from the public gallery.

## Design preview and automatic demo fallback

The site currently presents **24 sample vector graphics stored locally at `assets/demo/`** when `data/portfolio.json` has no valid entries. They are marked on screen as **Design preview** and are **not** presented as drawings from the artist. Once the first genuine approved work is added to `data/portfolio.json`, all placeholder photos disappear automatically. The demo manifest is in `data/demo-portfolio.json` and can be removed at launch.

The future admin dashboard will accept one-step bulk uploads and will create manifest/database entries automatically. Manual GitHub entries above are only an interim development path, not the planned experience for the artist.
\nThe v0.4 demonstration collection has **no external photo dependencies**: all SVG files are served directly from the same Hostinger domain. This avoids blocked third-party photo domains and broken image requests. The examples are illustrative mock images for layout testing, not genuine commission work.\n
## Phase 7 — actual private upload workflow

The authenticated **Artwork** tab now supports multi-select uploads directly from your computer, no metadata forms. Images are re-encoded to two WebP sizes and stored only in private Hostinger server storage. Uploads default to drafts; click Publish or select several drafts and Publish selected. No originals or private attachments are committed to GitHub. See [BULK_ARTWORK_PHASE7.md](BULK_ARTWORK_PHASE7.md). The write flag `DMO_ARTWORK_WRITES_ENABLED` defaults to 0 until controlled live upload and backup testing.


## Verified operational milestone — 2026-11-23

The owner tested real image upload, publication and unpublication through Artist's Desk and confirmed all three work. Phase 7's essential functionality has therefore been owner-tested. Backups of the PRIVATE Hostinger image storage and a recovery rehearsal remain outstanding. Uploaded images are stored separately from the public GitHub repository.

## Library browsing and optional descriptions

The Artist's Desk retrieves up to the supported 2,000-item library, shows 60 drawings per screen, and can filter featured drawings or missing optional accessibility descriptions, and sort by public gallery order or upload age. Select all applies only to the current displayed page. Descriptions are optional plain text (240 UTF-8 bytes), not titles or required upload fields. Publishing now verifies both optimized files against recorded byte lengths, WebP type and dimensions before exposing them. Failed or incomplete library refreshes lock editing until a successful reload. No existing artwork or production settings were changed.

## Atomic bulk visibility

A selected page of up to 60 drawings is validated and changed in one transaction, locking rows in ID order. Stale visibility, duplicate IDs, unknown records, or invalid optimized files reject the entire batch. No file deletion occurs. Each affected artwork receives an audit event. On a lost connection or conflict the UI locks further writes until reloaded. Explicit confirmation and CSRF remain mandatory.