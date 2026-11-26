# Artwork library editing safeguards

Unsaved sort order and optional accessibility descriptions are retained in memory across filters, pages, tab changes and reloads of the library. A failed server save does not discard the fields. Successful saves clear only the saved field. If a refresh discovers a changed server value, the stale local edit is blocked until discarded. Browser page reload or sign-out is not a persistence mechanism. Bulk selection remains deliberately page-scoped, with an exact count and indeterminate select-all state. Empty sort input is rejected rather than converted to zero. No change to artwork publication gates or production artwork.

Browser regression exercises a rejected save, successful retry and discarding another pending field. No real artwork is used.

Session XIX adds an explicitly confirmed atomic save of up to 60 optional metadata fields. The server locks artworks in ID order, checks each expected field, and applies no partial changes on conflict. Already-applied values are no-ops; the UI reconciles them after refreshing an interrupted save. No publication or file action is included. Individual edits remain supported.
