# Authentic public gallery and server-rendered portfolio directory

Explicit development preview preserves labeled samples and the legacy manifest. Public presentation (including settings-service fallback) uses only the artist-published database manifest. Empty and unavailable states never load demo or legacy manifests. Static sample hero art is hidden until preview or authentic artwork is established. No existing artwork is modified.

The new /portfolio.php renders published database artwork as accessible HTML links to individual drawings, 24 per page, with lazy thumbnails and no JavaScript requirement. Featured/sort/id ordering, escaped alt text, no private paths or drafts. Invalid or empty later pages return 404; database failure returns 503. Pagination is bounded at 1,000 pages. The directory remains noindex during launch preparation and is not added to the sitemap. Existing artwork detail and sitemap indexing behavior remains unchanged.

Existing legacy artwork must be uploaded and published through the dashboard before public-mode use. Commercial switches, design activation and policies remain unchanged. Disposable artwork HTTP tests cover draft/published/unpublished visibility and escaping; cross-browser tests cover empty and failed public gallery responses. Live diagnostics check the new page and assets without mutating data.

## Large gallery browsing and missing artwork

The homepage intentionally remains bounded to 500 published database images; the API reports whether more are available and the homepage links to the complete paginated directory. The sitemap covers the full supported 2,000-image library and requires both recorded image variants. Existing indexing controls are unchanged. A missing authentic artwork image now uses a neutral, explicitly unavailable graphic instead of the decorative design sample; the latter remains preview-only. The artist library filters use a single-column layout at narrow widths to resolve the 320px browser regression. No real artwork is altered.

Follow-up: showcase regression now checks the expected artwork or explicit unavailable graphic per known failing image; listeners are attached before image requests and ignore empty sources. No production artwork changed.


Hosted manifest must return explicit success, at most 500 records, and valid typed continuation metadata when supplied. Individual hosted records require matching canonical optimized image URLs and positive bounded dimensions; static legacy files remain preview-only. Scroll layout appends 24 thumbnails per load rather than creating all gallery elements initially; navigation through the lightbox still covers the loaded manifest. A restored public document reloads to recheck publication state. Tests cover malformed success responses and 65-item staged loading.
