# Public portfolio filters

The directory supports all, featured, portrait, landscape and square views. The server uses an allowlist and the stored oriented display dimensions, requires published status and both variant records, preserves the filter across pagination, and keeps the existing noindex policy. No categories, descriptions, uploads or metadata changes are required. Individual drawing links preserve the selected view, and previous/next navigation stays within that view. A stale filter falls back to the overall published sequence. Returning directly to the directory preserves its original page; subsequent neighbour navigation returns to page one. Canonical artwork URLs remain independent of the view.


Session XIX adds optional literal search against published alt descriptions, result counts, search-preserving filters and detail navigation. Detail return pages are computed from current stable gallery rank rather than a stale caller-supplied page. Search is optional; images without descriptions remain visible in unfiltered views. Permanent sharing links still omit search and filter parameters. No private metadata or draft artwork is searchable.
