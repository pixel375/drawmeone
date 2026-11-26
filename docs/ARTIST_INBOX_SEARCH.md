# Artist inbox search — Session XXII

Private admin-only literal substring search across message body, sender display name and commission reference. Type filter remains independent and is applied with search; reply-due selects only the latest eligible customer message. Matching count and page are read within one transaction. Changed unapplied search text invalidates rows and in-flight results. Search resets on sign-out. No new customer-visible fields, no automatic messages and no access gate changes. Browser and disposable HTTP contracts cover scope and invalid search.
