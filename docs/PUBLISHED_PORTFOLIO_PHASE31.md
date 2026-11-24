# Phase 31 — Published portfolio resilience

The existing demo gallery browser checks now also exercise a synthetic published portfolio (four valid records, two invalid records, and an optional legacy duplicate), without publishing or accessing real artwork.

Three fixes in gallery.js:
- An unavailable legacy static manifest no longer hides a successfully fetched valid database portfolio.
- The authoritative database record wins duplicate URLs instead of being overwritten by stale static metadata; cross-artwork thumbnail IDs are rejected.
- Each lightbox navigation resets the missing-image fallback guard so consecutive broken displays can both show the placeholder.

The regression checks cover sample notice removal, thumbnail/full image distinction, duplicate precedence, invalid URLs, switching site layout with genuine entries, individual artwork links, consecutive display failures and recovery to a valid image. All fixtures use browser-intercepted synthetic SVG data, no production publication, no customer information. The production script URL is bumped to gallery.js?v=13.

No feature gates or deployment credentials changed. Initial CI and Hostinger verification pending.