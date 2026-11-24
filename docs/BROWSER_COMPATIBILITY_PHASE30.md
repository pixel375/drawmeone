# Phase 30 — Cross-browser compatibility

The existing public, Artist Desk, customer commission and onboarding browser contracts now accept DMO_BROWSER_ENGINE=chromium|firefox|webkit (default: chromium). Unknown engine names fail immediately.

A separate GitHub Actions workflow runs the same four contracts on pinned Playwright Firefox and WebKit in parallel. Both jobs are independent and report real failures; there is no continue-on-error. Each job attempts every suite, even if one fails. Screenshots are engine-specific and retained for seven days.

The existing Chromium axe-core accessibility suite is unchanged. Its workflow now also triggers for nested Artist Desk, app and API changes, as does the compatibility workflow.

Tests use local PHP and synthetic browser API responses, not live customer data or payment providers. No production feature gates, public design, assets, configuration or database schema are changed. Playwright WebKit is an engine-level check, not certification on actual Safari/iOS hardware. Firefox similarly does not replace physical device testing.

Initial Firefox/WebKit execution is pending; do not infer browser compatibility from the previous Chromium pass.

## Initial results and narrow artwork panel fix

Initial run [45490849999](https://github.com/pixel375/drawmeone/actions/runs/45490849999): Firefox passed all four suites; WebKit passed public gallery, commission and onboarding, but reported a 363px document width in the 320px Artist Desk Artwork tab. Chromium, disposable database integration and source quality passed separately. Native file inputs and intrinsic grid sizing are now constrained without hiding document overflow; the existing test still requires the full document to fit. The admin stylesheet cache version and Hostinger diagnostic asset check are updated to v24. The expanded reflow diagnostic reports internal element overflow to help identify any remaining issue. Fix verification is pending.
