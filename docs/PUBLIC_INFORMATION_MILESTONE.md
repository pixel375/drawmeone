# Public service information and immutable policy HTML

/services.php displays approved, active, valid price estimates from the same catalog helper as the request form. Prices are not final quotes; the page reflects pause, capacity, unavailable and open states and works without JavaScript. FAQ describes existing workflow only, with no invented cancellation, licensing, delivery time or revision terms.

/policy.php renders the exact current or explicitly pinned historical policy selected by the shared JSON/HTML resolver. Version/digest mismatch gives 404; drafts remain inaccessible. Text is HTML-escaped, preserves line breaks, and can be printed using browser print. Existing policy.html remains compatible for old bookmarks. New pages are noindex, no-store, no third-party scripts. All business flags and actual policy approvals are unchanged.

Disposable HTTP tests verify exact historical/current policy content, wrong pin rejection, draft/published/unpublished service display, currency estimates and paused intake. Live diagnostics verify deployment without changing records. Public navigation wiring follows separately.

Session XXV: public services cards show only compatible published extras, with explicit empty restrictions, per-extra currency/estimate and mixed-currency quote warning. The separate extras directory remains visible; no drafts, compatibility revisions, or unpublished extras are exposed. Added custom-idea FAQ and real-HTTP restricted/unrestricted tests. Existing intake and pricing gates remain authoritative.
