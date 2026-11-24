# v3.9 — Customer onboarding and recovery browser QA

Implemented November 24, 2026. **Public customer registration, new commission submissions, quote delivery and payments remain disabled.** No production customer or account tokens were created.

## Better signup, sign-in, and recovery UX

- A second signup password field is required. Mismatched confirmation is rejected locally before any request is sent to the server. The password must also fit the backend's 128-byte limit.
- An accepted login followed by a transient failure to fetch account details now reports that distinction and recommends refreshing before trying again; it no longer falsely says the credentials were wrong.
- Login, signup, password reset and verification provide clearer messages for rate limits and invalid verification or expired links.
- A successful password reset provides a direct sign-in link. Expired links show **Request a new recovery link**.
- Temporary password reset errors keep the confirmation form available and retain the entered password for a safe retry. A confirmed successful reset clears the password fields; expired links clear them and hide the unusable form.
- The browser strips one-time email verification and reset tokens from the URL fragment as soon as the page opens. When access is not yet enabled, it instructs users to reopen the original link **from their email**, rather than implying the stripped URL can be reused.

## Server-side verification hardening

- `api/auth/verify.php` now requires the token to belong to a **pending, unverified customer**. A pending administrator's token is rejected by the public customer verification endpoint.
- On successful customer verification, **all** unconsumed verification tokens for that account are consumed, not only the one submitted. Expired, replayed and parallel links remain unusable.
- `tests/http_auth_contract.php` validates both invariants in disposable MariaDB. The existing password reset tests still ensure all sessions and parallel password reset links are invalidated.
- No CAPTCHA bypass, authentication switch, registration enablement or payment-related configuration was added to production.

## Chromium browser tests

A new `tests/browser_onboarding_smoke.cjs` is part of the existing browser QA workflow. It intercepts all API requests and replaces Turnstile with a strictly **synthetic local script**, so real accounts, Cloudflare challenges, email and paid services are never contacted.

The suite covers:

- Disabled accounts and recovery pages at 320, 390 and 768 pixels, including clean token URLs and no horizontal overflow.
- Existing-customer login being available while registration is disabled.
- Signup with mismatched passwords (no POST), matching passwords, a generic verification email response, verification resend, sign-in rate limit and eventual sign-in/sign-out.
- Valid and expired email verification links.
- Password reset request rate limits and generic responses.
- Mismatched new passwords, successful reset, temporary failure with retry, and expired reset links with a clear next step.

[Passing full Chromium workflow](https://github.com/pixel375/drawmeone/actions/runs/45465658442) includes `ONBOARDING_BROWSER_SMOKE=PASS` alongside public website, Artist's Desk and commission browser tests.

[Passing synthetic MariaDB integration](https://github.com/pixel375/drawmeone/actions/runs/45465632043) includes the stricter email verification rules.

## Outstanding before launch

Real SMTP, Turnstile and account onboarding must be tested with a controlled authorized customer account only when policies and launch gates are ready. Approved Terms/Privacy, genuine artist portfolio, independently protected off-site backups/recovery key, and payment-provider verification remain outstanding. These tests do not authorize enabling public features.
