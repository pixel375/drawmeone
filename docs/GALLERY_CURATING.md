# Gallery curation

Gallery-order mode exposes earlier/later controls for adjacent published drawings within the same featured group. The server checks adjacency and both expected sort values under a transaction; tied ranks are rebalanced inside that group only. All changed rows are audited. Draft visibility, featured status and images are unchanged. Unsaved sort edits block moves. Tests cover tied ordering and stale requests.
