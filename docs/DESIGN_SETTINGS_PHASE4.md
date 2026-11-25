# Draw Me One — Admin-managed visual settings (Phase 4)

Updated 2026-11-17. This is a secure **draft/publish backend foundation**, not the final public site theme picker.

## What was added

- Admin-only GET/POST `/api/admin/design.php`.
- A protected form in `/studio-admin/index.php` for site style, colour palette, logo, gallery layout and default light/dark setting.
- Two database rows in the **existing** `dmo_site_settings` table, one for `context='draft'` and one for `context='published'`. No migration is required.
- Drafts are separate from published settings. Explicit Publish copies only the last saved, fully validated draft.
- Public `/api/site-settings.php` returns **only published** allowlisted values, including the logo.
- Optimistic version checks reject stale browser tabs with HTTP 409. A reload is required after a conflict.
- Admin role, active verified session, strict HTTPS and same-origin JSON request, CSRF header, transaction and audit entry required for every save or publish.

## Design limits deliberately enforced

- Studio is the **only publishable site style** until the artist approves a genuinely better alternative.
- Allowed palettes: noir, canvas, editorial, aurora, ember.
- Allowed gallery layouts: masonry, mosaic, rows (Scroll).
- Allowed default visitor mode: dark or light.
- Logo: original or one of the 10 existing SVG logo options.
- Unknown keys and values are rejected. No freeform CSS, HTML, passwords, payment secrets or tracking scripts can enter this settings document.
- All choices are **admin-only once the website goes live**. The public Design Lab remains an experimental preview for now, and must be removed or hidden before launch. Only the light/dark control remains visitor-facing.

## Private environment setting

No database migration or new password is needed. Add this switch to your private Hostinger `.env`:

```dotenv
DMO_DESIGN_WRITES_ENABLED=0
```

It defaults to **0** even when missing. Leave at 0 until admin authentication and CSRF are independently tested with your controlled account.

When it is safe to test:
1. Configure your admin keys/private settings and verify login works.
2. Ensure `DMO_ADMIN_ENABLED=1` and `DMO_DESIGN_WRITES_ENABLED=0`.
3. Open Artist's Desk → Website settings. It should load the draft/published versions but block Save/Publish.
4. For a controlled trial only, set `DMO_DESIGN_WRITES_ENABLED=1`.
5. Save a draft. Verify it does not change the published API. Reload the draft; confirm values persist in MySQL.
6. Publish. Verify `/api/site-settings.php` returns only the newly published settings and the version increments.
7. Test unauthorized GET/POST, missing CSRF, bad values, stale version, unknown logo, and unsupported site style; these must not modify the database.
8. Reset `DMO_DESIGN_WRITES_ENABLED=0` after the test, until the public theme runtime has been finalized.

**Important:** The current public home page still uses the development Design Lab, which stores preview choices in visitors' localStorage. A published setting is now available to the API, but **the public UI is not yet switched over to admin-managed themes**. This separation is intentional until launch and avoids unexpectedly replacing preview themes while the site is still being designed.

## Future

- Move all design controls out of the customer-facing website.
- Apply published settings on the public page, while letting visitors override **only** light/dark mode.
- Add version history/rollback and other administrative content (SEO, commission text, prices, packages).
- Attach a preview thumbnail and colour swatches for each choice without exposing admin controls to visitors.

## Public presentation runtime follow-up

The public homepage now reads the allowlisted published settings endpoint. The separate private switch `DMO_PUBLIC_DESIGN_ENABLED=1` selects the production presentation; it is **not** enabled by this development pass. When off, the API explicitly selects the existing Design Lab preview. In public mode the saved published Studio layout, palette, gallery layout, default mode and logo apply; the visitor may override only light/dark, stored separately from preview preferences. Preview settings are ignored in public mode, including during first paint. Unavailable or invalid settings use safe Studio defaults and hide the Design Lab. No draft is public, and the original noindex launch restriction remains. A fresh page load reads the current published version. This does not change registration, intake, payments, portfolio publishing or policy approvals.

The admin overview reports whether a published presentation is ready. Synthetic browser and disposable HTTP tests exercise published settings, draft isolation, stale publish, disallowed styles, failed API fallback and separate visitor mode. Owner approval is still required before flipping the private switch.
