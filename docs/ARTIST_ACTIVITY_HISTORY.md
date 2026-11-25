# Private commission activity history

Artist request detail includes a bounded latest-50 event timeline from an explicit allowlist of existing audit events. It returns only fixed human-readable labels and UTC timestamps; raw audit payloads, digests, payment identifiers, names and message text are never returned by this projection. Customer endpoints are unchanged. The timeline is collapsible, and an explicit truncation notice indicates older events remain stored. It is a summary, not an exhaustive audit export. Synthetic tests exercise private projection and the browser panel.
