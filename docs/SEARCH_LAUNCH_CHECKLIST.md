# Search and social launch preparation

Status: prelaunch; indexing is not approved. No search-console submission or external account action.

## Current protections

Homepage, directory, policies, services, customer and admin pages use noindex metadata or headers. Published artwork detail and image responses also have noindex until an explicitly approved release. Apache adds a site-wide noindex header, including static assets. robots.txt allows fetching so cooperative crawlers can see noindex; robots.txt is not access control. The dynamic sitemap lists only published portfolio records with both expected variants, plus the homepage; it is a prepared discoverability artifact, not permission to index. Draft and private endpoints remain access-controlled. Noindex cannot guarantee removal of cached or noncompliant search results.

## Owner-approved social art

Artwork detail pages already provide permanent canonical URLs, escaped Open Graph title/description/image/alt and dimensions, ImageObject JSON-LD, and copy/share controls. A published real drawing can be selected by the artist as the social share link. It must have appropriate publication permission; no demo or draft artwork. Homepage social preview remains neutral without an artwork image until owner selects and approves one. Do not insert a random drawing or publish on the owner's behalf. External platforms may cache prior previews.

## After owner approval only

1. Approve actual art, public copy and business identity, including permission for social/search reuse. Choose a specific public drawing for social preview or retain neutral homepage metadata.
2. Review real public rendering, canonical URLs, structured data, image dimensions and alt descriptions. Validate sitemap and no private/draft URLs. Check HTTPS, redirects, 404s and unpublication.
3. In a separate reviewed release, remove the global prelaunch noindex header and selectively permit indexing for approved homepage, portfolio/art pages and appropriate public information. Keep account, recovery, commissions, admin, API JSON, private images and draft/error pages noindex. Do not remove all noindex markers indiscriminately. Review filtered/search duplicate URLs and canonicals.
4. Verify live headers and meta tags, public image response cache behavior and actual published-art sitemap. Confirm noindex still applies to private URLs; do not expose private data to crawlers.
5. Owner-authorized Search Console verification and sitemap submission; monitor coverage, stale previews, errors and removals. Search engines decide whether and when to index. Keep a rollback plan (restore noindex; preserve access controls; use appropriate removal tools for exposed URLs).

See LAUNCH_TODO.md SEO-03/04 and OPEN-07. No indexing was enabled.
