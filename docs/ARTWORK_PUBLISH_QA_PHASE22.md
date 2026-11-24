# v3.4 — Complete portfolio publishing verification and bulk gallery controls

Implemented November 24, 2026.

## What changed in Artist's Desk

The Artwork library can now be filtered between **All drawings**, **Private drafts** and **Published drawings**, with a visible count for each status. The filter operates on the actual authenticated library response; there is no placeholder metadata or additional database query.

A single checkbox per displayed drawing supports batch actions:

- **Publish selected drafts** operates only on selected drafts.
- **Unpublish selected drawings** operates only on selected published images, after an explicit confirmation describing the loss of public visibility.
- Bulk unpublishing uses the existing audited, CSRF-protected per-artwork API; the media and database records remain in private storage for future republication. It is **not** deletion.
- Changing filters clears selection to avoid applying a batch action to hidden items.
- The upload/publish/unpublish controls remain disabled if the private artist-write configuration switch is disabled, or while another artwork operation is running.

No new server permissions, upload switch, registration flag, payments or user records are enabled. All changes are committed to `main`, and the admin browser loads versioned v22 JS/CSS assets to avoid stale caches.

## True image lifecycle integration

`tests/http_artwork_contract.php` is now a required test in the disposable MariaDB workflow. It generates a synthetic PNG, signs in with a synthetic active admin session and exercises the real PHP HTTP endpoints:

1. Reject anonymous/invalid CSRF/wrong-origin and malformed image uploads.
2. Upload a valid PNG as an unlisted, private draft.
3. Verify both optimized WebP variants and opaque storage paths are outside the public web directory; no original PNG is saved.
4. Confirm the public portfolio JSON is empty and direct public-image access returns 404 for drafts, but a verified admin session can preview private WebP with `no-store`.
5. Publish with explicit CSRF, confirm the public portfolio JSON exposes only public-safe fields, and retrieve a WebP thumbnail through the public image route.
6. Reject unsupported public variants; update featured status and sort order; verify audit records and actual database state.
7. Unpublish, confirm the public manifest becomes empty and direct public requests return 404 again. The admin can still preview the private drawing.
8. Assert the entire flow created no payment records.

The synthetic database and files are disposable and deleted after testing. This **does not** publish real drawings on Hostinger or expose customer files.

GitHub Actions run [45437532651](https://github.com/pixel375/drawmeone/actions/runs/45437532651) passed the complete synthetic gallery lifecycle.

## Mobile browser coverage

The existing Chromium Artist Desk test now covers a mixed fake artwork library, status filter counts at 320/390/768/1440px and disabled controls when writes are unavailable. Additional synthetic UI checks cover bulk selection across private/published items, selection reset on filtering and cancelling an unpublish confirmation without issuing any network writes.

## Launch limits

The published gallery remains reliant on the artist uploading genuine rights-cleared drawings, with an intentional verification of their appearance and consent before release. A real authenticated Hostinger upload/preview/publish/unpublish pilot and independent encrypted media backup are still outstanding. Public customer registration, commissions and payment processing remain closed.
