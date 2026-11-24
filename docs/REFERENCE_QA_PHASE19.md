# v3.1 — Private customer reference image integration QA

Implemented November 24, 2026. Automated tests use **only synthetic images and disposable data**; public customer uploads remain disabled on Hostinger.

## Automated image lifecycle

The CI workflow now installs `php-gd` and runs `tests/http_reference_contract.php` after resetting prior synthetic records.

The test creates two fake verified customers, two separate requests and a 320 × 240 PNG entirely in a temporary, private directory outside the repository webroot. It calls the actual PHP routes through the localhost-only router:

- Missing CSRF credentials reject an upload, and a different customer cannot upload to another person's request.
- The owning customer uploads a valid image. The application decodes it, creates WebP thumbnail and display versions, and saves an opaque private storage key.
- Both converted files are confirmed to reside outside `public_html`.
- The owner retrieves the WebP through the authenticated, `no-store` image route. An anonymous visitor and the other customer are rejected.
- Cross-customer deletion and deletion without CSRF are rejected.
- Owner deletion removes the DB attachment record and both private image variants. Later image retrieval returns 404.
- A completed request cannot receive new references.
- No production database, real file, payment, or Google Drive source is used.

[Passing integration run](https://github.com/pixel375/drawmeone/actions/runs/45427818462).

## What remains

- Controlled browser/device testing of the complete reference uploader UI on desktop and mobile with an approved private pilot account.
- Client-side progress, connection interruption and 4 MB rejection UX review.
- Artist business/privacy policy approval before turning on any new public uploads.
- A live Hostinger writable-storage verification after choosing when to enable the private pilot. No production files were created by these tests.
