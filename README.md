# Draw Me One

**drawmeone.com** — an original-art portfolio and custom drawing commission website for an independent artist.

## Current status
- **v0.4 live design lab**: four structurally distinct whole-site styles (Studio, Sidebar, Journal, Immersive), five independent color themes (Noir, Ocean, Paper, Aurora, Ember), three gallery layouts (Masonry, Mosaic, Horizontal Scroll), dark/light mode, remembered preferences, 24 self-hosted demonstration images, responsive design and lightbox. Demo visuals are clearly labelled as examples and automatically disappear once real approved artwork is added to `data/portfolio.json`.
- Quote/request, payment, contact and admin tools are **not active yet**. We will not accept customer personal data or payments before an agreed secure implementation.

**Read first:** [Development roadmap](docs/ROADMAP.md) · [How to add approved artwork](docs/ARTWORK_GUIDE.md)

## Current stack and hosting
Static HTML/CSS/JS deployed from the root of GitHub `main` to Hostinger shared hosting. No Node runtime, backend, database, build step, secrets, or API tokens needed yet.

- `index.html` — public landing page
- `styles.css` — design and responsive styles
- `gallery.js` — validated, accessible image gallery and full-screen viewer
- `studio.js` — live theme, gallery layout and dark/light controls
- `data/portfolio.json` — **real artist work only**, currently `{"works":[]}`
- `data/demo-portfolio.json` — 24 locally hosted demonstration SVG artworks used only while the real portfolio is empty
- `assets/demo/art-XX.svg` — locally stored demo visuals (zero external photo dependency)
- `assets/demo/placeholder.svg` — local image-error fallback
- `assets/portfolio/` — artist-approved original work for public display
- `robots.txt` and `sitemap.xml` — initial search discovery files
- `docs/` — planning and publishing guides

### Future architecture
Hostinger-supported PHP + MySQL/MariaDB with PDO, secure admin login, upload processing, server-side-rendered portfolio images, quote requests, and hosted payment checkout. Keep private commission references, authentication secrets, and full-res masters out of GitHub and the public webroot.

## Release safety
No unapproved artwork, prices, personal addresses/emails, fabricated testimonials, real customer contact forms or payment handling without the artist/project owner's sign-off.

## v0.4 interactive design lab
Visit https://drawmeone.com/ on desktop or mobile and expand the floating **Design Lab** panel.

### WEBSITE STYLE — the whole site's layout
1. **Studio:** balanced split hero, section-based browsing and guided story.
2. **Sidebar:** sticky left navigation on desktop and portfolio-first page structure.
3. **Journal:** oversized editorial masthead and typography, panoramic cover and horizontal process cards.
4. **Immersive:** full-screen cover, floating navigation and cinematic gallery presentation.

### COLOR THEME — independent of website style
- **Noir** (black, purple), **Ocean** (navy, cyan), **Paper** (warm neutral), **Aurora** (deep green, lime), and **Ember** (burgundy, orange).
- Dark and light color modes, with the visitor's choices persisted locally.

### GALLERY — independent of website style and color
- **Masonry**: natural aspect ratios, image-column arrangement.
- **Mosaic**: editorial grid with consistent cells, preserve complete art via object-fit contain.
- **Scroll**: horizontal, touch-friendly scroll-snap gallery.
- For Masonry/Mosaic, initially show **12 images on desktop / 8 on mobile** (roughly four rows); each **Load more images** click adds the next 12/8. The horizontal Scroll view shows all images.
- All 24 demo items are loaded from `assets/demo/` on our own Hostinger server, not Picsum or an external hotlink. When any valid artist-approved works are entered in `data/portfolio.json`, the demo gallery is automatically replaced by authentic artwork. Demo files never appear as real portfolio entries.

**Temporary SEO status:** the home page has `noindex,follow` for the design-testing period so Google doesn't index placeholder content. Remove this only when authentic work and live commission information are ready. No contact forms, personal-data collection, payment acceptance or backend features are active yet.

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
