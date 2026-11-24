# v3.0 — Customer verification and account-recovery hardening

Implemented November 24, 2026. This release improves account safety **without opening registration or commission intake**.

## Changes

- Password-reset confirmation now requires a valid, unconsumed, unexpired reset token belonging to an **active, email-verified customer**. An administrator token or a token for an unverified/disabled account cannot use the public customer reset endpoint.
- After successful password change, all outstanding reset tokens for the account are consumed, preventing replay or concurrently issued reset links from remaining valid.
- All previously issued customer sessions are deleted after successful recovery, signing out the account on its existing devices without affecting another customer.
- A transactional **password changed** security notice is attempted **after the database transaction commits**. The email contains no password or token. A mail outage does not roll back a successful password change or mislead users about their credentials.
- Recovery confirmation retains original HTTPS, same-origin JSON, rate-limiting, strong password hashing and audit records.

## Disposable integration tests

`tests/http_auth_contract.php` runs real verification and reset PHP endpoints through the local, CI-only PHP router against an ephemeral MariaDB schema with synthetic customers.

It tests:
- Pending accounts cannot authenticate; a valid verification token activates them.
- Invalid, expired and replayed verification tokens fail.
- Cross-origin verification and reset fail.
- Invalid password length and expired/reset-replayed tokens fail.
- An administrator token cannot be used in public customer recovery.
- Password hash is replaced, old passwords stop validating, and all outstanding links become unusable.
- Two existing devices are signed out while an unrelated customer's session remains valid.
- No payments or production records are touched.

[Successful full CI run including auth](https://github.com/pixel375/drawmeone/actions/runs/45427127474).

## Remaining before customer launch

- Real end-to-end signup, resend verification, sign-in, forgot password and CAPTCHA with a controlled customer account and live SMTP in a deliberately authorized pilot.
- Real-world delivery confirmation for the new post-reset security email.
- Account/privacy policies and retention procedures approved by the business owner.
- External recovery-key custody and independent off-site backups.
- No customer registration, quote, order, or payment flags enabled by this code change.
