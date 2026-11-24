# DrawMeOne v2.4 — Search-engine-readable published drawings

Implemented November 23–24, 2026.

## Public artwork pages

- The new /artwork.php?id=N page is server-rendered in PHP and exists only if drawing N has visibility=published, an associated display asset and a readable private WebP. Drafts/removed drawings never generate a visible page.
- The response includes semantic HTML, a canonical URL, OpenGraph image, accurate ImageObject JSON-LD and a responsive uncropped display. No artist identity, medium, pricing, license or description is invented.
- The artwork's optional existing alt text is used outside the image on its own detail page; the bulk-upload workflow still requires no manual metadata.
- The portfolio modal now shows View drawing page only for published database artwork, never for demo SVGs or unrelated static images.

## Sitemap

- /sitemap.php emits a generated XML image sitemap listing only published database artwork. Root .htaccess serves it as /sitemap.xml, which is already referenced by robots.txt.
- Includes the homepage even with zero published drawings. A drawing appears automatically after publication and disappears from the dynamic list after unpublishing.
- Does not index customer reference images, drafts, admin pages, accounts, request pages or any third-party content.

## Verification and remaining work

- [x] Live Hostinger check succeeded (Action run 45319307505): /sitemap.xml HTTP 200 with the dynamically generated image namespace; /artwork.php?id=1 HTTP 404 while no artworks are published.
- [ ] Once real artist-approved drawings are published, check each page and sitemap image link in a browser and Google Search Console.
- [ ] Consider optional descriptive titles or categories only with the artist's approval; do not require typed captions for batch uploads.
- [ ] Replace public demo artwork only with approved real artist artwork. No new artwork was fabricated in this phase.

## Phase 32 — Publication-aware caching and HTTP coverage

Public images now require revalidation. A SHA-256 ETag allows unchanged published variants to return 304 without retransmitting image bytes, but only after checking the current published row and readable file. Private previews and missing/revoked responses use no-store. Public detail pages and the sitemap also require revalidation rather than remaining fresh for five minutes. This cannot revoke bytes downloaded earlier or forcibly invalidate old caches which still hold the former one-hour freshness policy.

The disposable MariaDB HTTP test now exercises draft/published/unpublished detail pages and sitemap entries, escaped hostile synthetic alt text, conditional public image requests, and rejection of a previously valid ETag after unpublishing. The CI-only router permits these two public endpoints in the isolated local test. No real artwork, production data or feature gates are changed. Initial verification pending.
