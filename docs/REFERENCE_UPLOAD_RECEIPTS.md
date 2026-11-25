# Private reference upload receipts

Customer reference uploads now use optional UUIDs and keyed digests scoped to the authenticated customer and request. The request lock serializes receipt lookup, capacity validation and insertion. Replays return the saved attachment; removed attachments cannot be recreated by replay. The current upload gate still applies. Browser resume keeps the ambiguous file and key in memory; an explicitly selected new file gets a new key. No raw image data or filename is added to audit storage. Tests exercise replay, ownership and deletion.
