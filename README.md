# Draw Me One

**drawmeone.com** — an original-art portfolio and custom drawing commission website for an independent artist.

## Current status
- **v0.2 foundation**: responsive landing page and image-only portfolio shell, gallery viewer, image manifest, SEO basics. The gallery intentionally has **no images until the artist supplies and approves originals**.
- Quote/request, payment, contact and admin tools are **not active yet**. We will not accept customer personal data or payments before an agreed secure implementation.

**Read first:** [Development roadmap](docs/ROADMAP.md) · [How to add approved artwork](docs/ARTWORK_GUIDE.md)

## Current stack and hosting
Static HTML/CSS/JS deployed from the root of GitHub `main` to Hostinger shared hosting. No Node runtime, backend, database, build step, secrets, or API tokens needed yet.

- `index.html` — public landing page
- `styles.css` — design and responsive styles
- `gallery.js` — validated, accessible image gallery and full-screen viewer
- `data/portfolio.json` — portfolio manifest; currently `{"works":[]}`
- `assets/portfolio/` — artist-approved original work for public display
- `robots.txt` and `sitemap.xml` — initial search discovery files
- `docs/` — planning and publishing guides

### Future architecture
Hostinger-supported PHP + MySQL/MariaDB with PDO, secure admin login, upload processing, server-side-rendered portfolio images, quote requests, and hosted payment checkout. Keep private commission references, authentication secrets, and full-res masters out of GitHub and the public webroot.

## Release safety
No unapproved artwork, prices, personal addresses/emails, fabricated testimonials, real customer contact forms or payment handling without the artist/project owner's sign-off.
