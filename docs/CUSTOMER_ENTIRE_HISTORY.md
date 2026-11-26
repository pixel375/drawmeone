# Entire customer history search

Quick filters continue to work on loaded records. Search entire history applies server-side reference, subject, historical package name or workflow stage search, active/closed filtering and ordering before pagination. It remains owner-scoped and returns no internal IDs or private messages. Changing the controls returns to quick browsing. Search uses stored workflow stage terms rather than translated presentation labels; no data is written.

Session XXII: owned matching totals are computed with the same filters in the same read transaction as each page. Totals never include other customers, and private brief text is still excluded from search. Local quick-filter counts remain distinct from whole-history matches. Clear history filters restores the ordinary first page. Counts are live, not a frozen multi-page snapshot.

Session XXIV: customer attention prioritizes eligible quotes expiring within 72 hours, groups repeated references once, and distinguishes available actions/review from recent updates. No unread count, automatic reminder, or additional access gate. The attention view remains limited to ten references per update type.
