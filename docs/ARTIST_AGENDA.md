# Artist agenda

Two new request filters separate agreed active-order deadlines and unconfirmed customer-requested dates before an order exists. Optional inclusive date bounds are validated server-side, and rows group by calendar date. Existing customer scope and pagination remain. This is a read-only planning aid, not an automatic promise or scheduling mutation. Tests cover inclusive ranges, invalid queries and mobile rendering.
