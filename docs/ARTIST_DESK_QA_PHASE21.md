# v3.3 — Responsive Artist Desk navigation and Chromium admin QA

Implemented 2026-11-24.

## User-facing improvement

The Artist's Desk previously displayed all six navigation tabs in a horizontal scroll strip at narrow widths. This made the last tabs easy to miss on phones. The layout now uses:

- Desktop/tablet: the original single-line tab navigation.
- Mobile through 650px: three equally sized columns, two rows, all sections visible.
- Narrow phones through 360px: two columns and three rows for readable labels.
- Visible keyboard focus rings, consistent accessible selected-tab state (`aria-current="page"`) and no document-level horizontal overflow.

Changes: `studio-admin/panel.css`; versioned stylesheet in `studio-admin/index.php`.

## Safe browser automation

New `tests/browser_admin_smoke.cjs` runs in the existing GitHub Actions Playwright/Chromium browser workflow. No production account is used: all admin API responses are synthetic browser mocks and every attempted HTTP mutation is intercepted and explicitly rejected.

At 320, 390, 768 and 1440px, the test:
- Opens the actual PHP admin page and JS frontend, with a synthetic logged-in artist.
- Confirms overview counts and launch-readiness badges render.
- Clicks each of the six sections, checks the correct panel and accessible current tab, and verifies there is no document horizontal overflow.
- Validates request/message empty states, and that artwork upload/publish, catalog edit/publish, website design edit/publish and SMTP test controls remain disabled when server gates are off.
- Confirms all six tabs remain visible and within the nav container on phones.
- Confirms no browser JS exceptions or API write attempts.
- Verifies that an unavailable admin service leaves the login and workspace concealed.
- Saves synthetic screenshots with 7-day retention for optional visual review.

Both the pre-existing public-page browser suite and the new Artist Desk browser suite passed in GitHub Actions run [45434167222](https://github.com/pixel375/drawmeone/actions/runs/45434167222).

## Deployment check

The read-only Hostinger SSH workflow now verifies the live Artist Desk HTML references the new versioned stylesheet and that the correct mobile-grid CSS is served. This is separate from the local simulated browser session.

## Outstanding

No real production admin credentials are used in automated screenshots. Actual mobile-device interaction and approved artwork review are still recommended before opening customer registrations or payments. All public-facing feature switches remain unchanged.
