# Operational integrity and independent recovery follow-up

The prior offline recovery change passed source, disposable integration, Chromium and Firefox/WebKit suites. The production diagnostic now checks the new recovery files for PHP syntax and requires the bounded active-invoice cryptographic scan to complete.

Record consistency checks now flag missing reference, delivery, proof and checkout migration markers; cancelled requests with live quote offers or live orders; and cancelled orders with unclosed invoices, staged artwork or proof records. These are read-only counts, not automatic repair operations. The artist dashboard clearly separates record consistency from CLI-only file and invoice checks.

The CLI authenticates each active invoice's encrypted URL using its order binding, checks the HMAC fingerprint and compares its HTTPS host to the stored provider host. It does not contact a provider, confirm funds or expose URL text. Expired but still active links are included. Scan coverage is capped at 10,000 active links; incomplete coverage fails the diagnostic. Historical revoked/suspended links intentionally contain no ciphertext. This is not a provider availability or payment verification test.

Disposable integration deliberately creates inconsistent states in transactions and rolls them back. Coverage includes a missing checkout migration marker, cancelled request/order conflicts, valid invoice cryptography, wrong order binding, invalid fingerprint and malformed ciphertext. No live customer records are altered. Commercial gates remain unchanged.

Independent storage and separate recovery/application-key custody, final policy approvals, real onboarding and provider validation remain outstanding.
