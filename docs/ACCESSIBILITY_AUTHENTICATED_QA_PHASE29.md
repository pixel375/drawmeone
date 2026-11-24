# v4.1 — Authenticated accessibility and small-screen reflow

Implemented November 24, 2026.

## What is newly tested

The pinned axe-core Chromium test suite now includes authenticated **synthetic** screens, not only the public/closed site:

- **Artist's Desk:** Overview, Orders & quotes, Messages, Artwork, Services & pricing, Website settings at 320px and 1440px.
- **Customer workspace:** Signed-in history and a detailed request with a line-item quote, private message, and unpaid order at 320px and 1440px.
- Tests validate WCAG 2.0/2.1 A/AA axe rules; serious/critical findings are CI blockers, and lower-impact findings remain in the short-retention report.
- At a 320 CSS-pixel viewport, tests assert that the authenticated page does not require horizontal document scrolling. This is a **reflow approximation** for desktop magnification, **not** actual 200%/400% browser zoom or a screen-reader certification.
- All JSON returned to Chromium is synthetic and intercepted. Writes are rejected and asserted absent, and these runs never contact production authenticated APIs.

## Keyboard improvements

- Artist Desk native `select` dropdowns and `textarea` text fields now use the same highly visible `:focus-visible` outline as buttons and inputs.
- The Artist Desk and commission pages have keyboard-focusable `#main` landmarks. The first Tab + Enter on their existing Skip links now has an explicit focus destination.
- The mobile/desktop browser checks validate these skip links, not merely that the URL hash changes.
- `studio-admin/panel.css?v=23` is cache-busted to ensure deployed focus styles replace previous browser caches.
- Read-only Hostinger diagnostics check the updated CSS, focusable Artist Desk and customer main landmarks, and all existing security/registration gates.

## What this does not do

The authenticated pages are mocked in a local browser; no real customer records, live artist notes, quote response, private files or payments are accessed. The existing real-HTTP disposable MariaDB suites remain separate. Real device testing with screen readers, very large text, independent off-site backup and legal/customer launch approvals remain outstanding.

Automated accessibility tests catch many common defects, but human evaluation and actual assistive-technology testing are still needed before claiming compliance.
