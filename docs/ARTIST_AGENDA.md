# Artist agenda

Two new request filters separate agreed active-order deadlines and unconfirmed customer-requested dates before an order exists. Optional inclusive date bounds are validated server-side, and rows group by calendar date. Existing customer scope and pagination remain. This is a read-only planning aid, not an automatic promise or scheduling mutation. Tests cover inclusive ranges, invalid queries and mobile rendering.

Agreed agenda exports use the applied date and customer/search scope, cover every matching row up to 500 events and require an inclusive date range no more than 365 days apart. The ICS file contains all-day events, stable reference-based identifiers and dates only. No customer names, messages, links, payment records or private files. Import is manual and does not synchronize future changes.
