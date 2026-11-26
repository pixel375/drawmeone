# Intake draft recovery

Unsaved availability/capacity settings remain in page memory across readiness refreshes, never in local storage. A changed saved version blocks submission until the old draft is explicitly discarded. A failed or uncertain write requires a current read before retry, and a matching server state clears the draft. An overlapping overview success cannot overwrite a newer intake save. No commercial gate or production setting is changed by implementation. Synthetic browser tests cover refresh and conflict.
