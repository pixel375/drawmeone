# Adding artwork before the admin dashboard exists

Only use actual drawings supplied and approved by the artist. Never add stock art or AI-generated drawings as portfolio work.

1. Export a clean high-quality, sRGB **WebP, AVIF, PNG, or JPEG** image for each work. Keep private full-resolution originals somewhere safe. Aim for 1400-2200px along the longest edge as a starting point; measure visual quality and page speed.
2. Name each file descriptively and in lowercase, e.g. `assets/portfolio/ink-floral-portrait.webp`. Do not include personal names or private client information in URLs without consent.
3. Upload it to the `assets/portfolio/` directory in GitHub and add a record to `data/portfolio.json`.
4. The gallery reads a `works` array. Example (NOT a real drawing; **do not paste into production until the corresponding artist-approved file exists**):

```json
{
  "works": [
    {
      "src": "assets/portfolio/ink-floral-portrait.webp",
      "alt": "Black ink drawing of a flower bouquet with detailed leaves",
      "width": 1600,
      "height": 2100
    }
  ]
}
```

The visible gallery contains only images; no captions, titles, watermarks or text on top of any drawing. Descriptive alternative text is used by screen readers and search engines, **not displayed over the artwork**. Avoid descriptions that reveal private client details.

For now, the static page loads the JSON with JavaScript. Once the PHP+MySQL dashboard is available, migrate to server-rendered HTML `<img>` elements, optimised derivatives, per-work pages, and automatically generated sitemaps. This is important for reliable SEO across search engines.

Do not upload private reference images or confidential commission messages into the public GitHub repository or public hosting folder. The future admin must enforce file validation and private access.
