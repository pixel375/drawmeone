# v4.0 — Automated WCAG accessibility and keyboard navigation

Implemented November 24, 2026. This phase does **not** open customer registration, commissions, payment processing, or publish test images.

## Continuous accessibility regression testing

Added `tests/browser_accessibility_smoke.cjs`, run by the existing GitHub Actions Chromium browser workflow with pinned `axe-core@4.10.3`. The test covers WCAG 2.0 and 2.1 A/AA rules on actual rendered pages.

Pages and states:
- All four fully distinct homepage layouts (Studio, Atelier, Edition, Signature), **390px mobile and 1440px desktop in dark mode**.
- All four layouts at **1440px in light mode**, plus Studio mobile in light mode.
- The closed commission, customer account and password-recovery pages at 390px and 1440px.
- Keyboard access to the homepage's Skip to portfolio link.
- Keyboard Skip to main content links on account and password-recovery pages.
- Open gallery lightbox accessibility and returning focus to the originating drawing on close (new regression).

The workflow saves a structured `test-results/accessibility-report.json` alongside short-retention screenshots; the report uses static sample content only. **Serious/critical findings fail CI**, with rule, node target and summary. Lower-impact findings are still recorded rather than silently ignored. The non-WCAG `region` best-practice rule is excluded, but semantic navigational landmarks remain in markup. Automated scans are not a replacement for manual assistive-technology testing.

## Concrete defect repaired

The first axe run detected inadequate contrast on two helper captions in the desktop design picker: **website style explanatory label** and **brand-logo explanatory label**. These captions inherited muted text with `opacity:.7`. Removed the opacity, switched to full-contrast `var(--ink)`, increased sizing/weight, and versioned `styles.css?v=11` to prevent browser-cache discrepancies.

The subsequent [Chromium run 45468970978](https://github.com/pixel375/drawmeone/actions/runs/45468970978) passed dark/light layouts and the added account skip-link checks. Newer runs additionally cover the open lightbox.

## Practical keyboard improvement

`account.html` and `password-reset.html` now start with a visually hidden **Skip to main content** link that becomes clearly visible on keyboard focus, with a real `#main` target. This complements the existing public gallery and Artist's Desk skip links. It does not alter any API or authentication behavior.

## Limitations

- This is Chromium automation with synthetic API responses and fake demo images, **not a full WCAG conformance certification**.
- Safari, iOS VoiceOver, Android TalkBack, large-text zoom, color-blindness assessment, and real authenticated Artist's Desk/customer screens require additional checks.
- Compliance still requires proper real artwork descriptions, policy/legal approval and controlled real-device pilot before public launch.

## Final verification

- [Final comprehensive Chromium run 45469194540](https://github.com/pixel375/drawmeone/actions/runs/45469194540): **SUCCESS** including the opening-gallery axe check, focus return, both account skip links and WCAG scans across the four layouts in dark/light mode. `ACCESSIBILITY_BROWSER_SMOKE=PASS`.
- [Disposable database integration 45469194541](https://github.com/pixel375/drawmeone/actions/runs/45469194541): **SUCCESS**; all existing account/quote/order/media and backup recovery integration checks remain intact.
- [Source quality 45469194507](https://github.com/pixel375/drawmeone/actions/runs/45469194507): **SUCCESS**.
- [Hostinger read-only production check 45469404677](https://github.com/pixel375/drawmeone/actions/runs/45469404677): **SUCCESS**, including `a11y_public_assets=deployed`, existing mail/quote/order/private-image checks and customer registration/commission submission still disabled. No live account or payment action was executed.
