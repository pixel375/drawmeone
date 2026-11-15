# Draw Me One

**drawmeone.com** — an original-art portfolio and custom drawing commission website for an independent artist.

## Current status
- **v0.3 live design lab**: three distinct modern visual themes (Noir, Canvas, Editorial), three gallery layouts (Masonry, Mosaic, Horizontal Scroll), dark/light mode, remembered preferences, 10 temporary photographic examples, responsive design and lightbox. Demo photographs are **explicitly marked as examples only** and automatically disappear as soon as real approved artwork exists in `data/portfolio.json`.
- Quote/request, payment, contact and admin tools are **not active yet**. We will not accept customer personal data or payments before an agreed secure implementation.

**Read first:** [Development roadmap](docs/ROADMAP.md) · [How to add approved artwork](docs/ARTWORK_GUIDE.md)

## Current stack and hosting
Static HTML/CSS/JS deployed from the root of GitHub `main` to Hostinger shared hosting. No Node runtime, backend, database, build step, secrets, or API tokens needed yet.

- `index.html` — public landing page
- `styles.css` — design and responsive styles
- `gallery.js` — validated, accessible image gallery and full-screen viewer
- `studio.js` — live theme, gallery layout and dark/light controls
- `data/portfolio.json` — **real artist work only**, currently `{"works":[]}`
- `data/demo-portfolio.json` — 10 Picsum placeholder photographs used only while real portfolio is empty
- `assets/demo/placeholder.svg` — offline fallback for preview image errors
- `assets/portfolio/` — artist-approved original work for public display
- `robots.txt` and `sitemap.xml` — initial search discovery files
- `docs/` — planning and publishing guides

### Future architecture
Hostinger-supported PHP + MySQL/MariaDB with PDO, secure admin login, upload processing, server-side-rendered portfolio images, quote requests, and hosted payment checkout. Keep private commission references, authentication secrets, and full-res masters out of GitHub and the public webroot.

## Release safety
No unapproved artwork, prices, personal addresses/emails, fabricated testimonials, real customer contact forms or payment handling without the artist/project owner's sign-off.

## v0.3 interactive design preview

Open https://drawmeone.com/ on desktop or mobile. Use the floating **Design Lab** panel to compare:

- **Noir**: clean dark minimalism, defaults to Masonry.
- **Canvas**: cinematic full-image presentation, defaults to horizontal Scroll.
- **Editorial**: sophisticated serif/type treatment, defaults to Mosaic.
- Switch among the **Masonry / Mosaic / Scroll** gallery layouts independently of the design.
- Switch **Dark / Light**, or use the sun/moon toggle in the header.
- The panel can be minimized. Theme/layout/mode preferences are saved in browser local storage; no tracking is performed.

**Preview-only policy:** Sample photographs are fetched from `picsum.photos` and are not the artist's work. They will automatically disappear once real approved artwork is published. A generic local decorative SVG is used if the sample photos fail to load. Because this version deliberately contains unrelated sample photos, the home page currently uses `noindex,follow` to avoid being indexed with inaccurate image content. **Remove noindex after the genuine gallery and business content are launch-ready.**

This is a front-end preview, not a finished commission checkout or private media manager. No customer data is collected.
