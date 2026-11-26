# Phase 31 — Published portfolio resilience

The existing demo gallery browser checks now also exercise a synthetic published portfolio (four valid records, two invalid records, and an optional legacy duplicate), without publishing or accessing real artwork.

Three fixes in gallery.js:
- An unavailable legacy static manifest no longer hides a successfully fetched valid database portfolio.
- The authoritative database record wins duplicate URLs instead of being overwritten by stale static metadata; cross-artwork thumbnail IDs are rejected.
- Each lightbox navigation resets the missing-image fallback guard so consecutive broken displays can both show the placeholder.

The regression checks cover sample notice removal, thumbnail/full image distinction, duplicate precedence, invalid URLs, switching site layout with genuine entries, individual artwork links, consecutive display failures and recovery to a valid image. All fixtures use browser-intercepted synthetic SVG data, no production publication, no customer information. The production script URL is bumped to gallery.js?v=13.

No feature gates or deployment credentials changed. Initial CI and Hostinger verification pending.

## Regression results

The published-portfolio regression passed on Chromium, Firefox and WebKit in [Chromium run 45491298783](https://github.com/pixel375/drawmeone/actions/runs/45491298783) and [compatibility run 45491298800](https://github.com/pixel375/drawmeone/actions/runs/45491298800). The latter overall run failed separately in the 320px Artist Desk artwork panel; this does not invalidate the passed public gallery suite. Database integration and source quality also passed. Production deployment verification remains pending.


Session XVIII: The homepage gallery now exposes a retry control after failed manifests, bounded to one fetch sequence at a time. It still fails closed in public mode and never requests design sample manifests there. Loading/empty/error/success states are announced; the visible count remains available after the last load-more button disappears, with keyboard focus transferred to that count. Failed retries restore button focus and successful retries focus the result. Multi-touch/cancelled swipes clear their pending gesture. Browser contracts cover failed retry, recovered empty result, and final gallery count. No publication settings changed.

Session XIX: The full-image viewer now reports failed image loads, provides a bounded same-image retry, disables meaningless previous/next controls for a single drawing, and restores focus even if responsive layout changes replace gallery tiles. Existing public fallback remains a neutral unavailable image; no demo art is substituted.
