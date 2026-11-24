# Consolidated commission workspace milestone

Phase 38 passed browser, source, database and Hostinger checks. This batch replaces the previous single-change passes.

- Artist review status changes: request clarification, return to review, or decline an unquoted request; expected-state check, request lock, existing policy/write gates and audit. Requests with a sent/accepted quote or an order cannot use these actions. Closed requests reject new quote drafts. No automatic notifications or payment actions.
- Needs-review dashboard and list exclude sent/accepted quotes and orders. Existing historical status fields remain unchanged.
- Both private conversation views can explicitly expand their recent message window to the full bounded 500-message thread. Customer results always exclude artist-only notes. Artist message inbox has type filters and 50-record pagination.
- Browser asset versions, disposable HTTP tests and live read-only verification updated. No production flags changed. Initial CI pending.
