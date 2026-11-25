# Exact-file duplicate avoidance

Bulk uploads default to skipping exact original-file byte matches recorded in existing upload receipt HMACs. The artist may deselect the option before starting a fresh batch. The preference is retained for every file in a resumed batch. Replay checks precede duplicate lookup, and no duplicate skip creates a new artwork or changes visibility. Older unrecorded uploads and re-encoded files may not match. Invalid existing variants require explicit review; no file is overwritten or removed.
