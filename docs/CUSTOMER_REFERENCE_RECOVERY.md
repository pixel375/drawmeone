# Customer reference upload recovery

A customer can stop after the current image. Unattempted files are retained in request-scoped memory for resume. Confirmed uploads and ambiguous attempts are excluded from the resume list; an explicit rate-limit rejection preserves the current file. The list is refreshed before further uploads. Replacing remaining files requires confirmation. A request switch or sign-in reset clears private file references. The synthetic browser test checks interrupted uploads and unchanged-message key reuse. No production customer data or gate changes.
