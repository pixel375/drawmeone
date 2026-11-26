# Private customer directory

Authenticated artist-only lookup for commission support, returning bounded customer name/email, account status, email verification and commission count/latest submission. Literal search by name, email or commission reference, cursor pagination and account status filters. No administrator identities, credentials, session tokens, private artwork or provider references. Exact customer ID request scope avoids confusing people with identical names; the active scope is labeled and can be cleared. Read-only, no impersonation, account disable, deletion, password reset or email sending. Customer records and search inputs are not persisted in browser storage. Synthetic HTTP and browser/accessibility coverage; no production identities created or modified.


Changing exact customer scope now closes and clears previous commission details/drafts; changing scope is blocked while a mutation is outstanding. Payment shortcuts reset any previous customer scope before opening a commission. Failed directory or finance reads clear stale records.

Session XXI: commission-history presence filter and matching count, scoped to customer accounts only. Filters invalidate stale results until submitted; clear filters reloads defaults. Older-page failures preserve existing results and allow retry. A non-decreasing cursor cannot loop. No account mutations or additional personal fields.
