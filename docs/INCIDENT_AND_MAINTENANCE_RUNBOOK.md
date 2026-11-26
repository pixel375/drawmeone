# DrawMeOne incident and maintenance runbook

Preparation only. Keep public indexing, customer activation and commercial gates unchanged until separate owner approval. Do not change production data, credentials, provider records or legal policy during diagnostic checks.

## First response

1. Record UTC time, affected feature, release SHA and observable error code; do not copy credentials, raw invoice URLs, customer messages or artwork into GitHub issues.
2. Identify whether the issue is availability, confidentiality, financial reconciliation, data integrity, provider delivery, or hosting exhaustion. If uncertain, restrict the affected operation rather than guessing.
3. Pause new intake through the approved control. Disable affected writes only with authorized configuration access; do not assume disabling a site link closes an external invoice. Preserve existing customer history and account access where safe. Escalate any actual breach or legal notification decision to the responsible owner.
4. Review the latest GitHub source/DB/browser runs, exact-release SSH diagnostics, integrity counts, encrypted backup freshness and independent recovery status. Never treat a clean diagnostic as proof that every private file or external provider action succeeded.
5. For payment disputes, independently verify the receiving account and evidence; use the approved payment hold workflow, not direct database changes. For unauthorized artwork access, restrict future access; previously downloaded copies cannot be recalled.

## Read-only evidence

- Configuration: `php app/cli-config-review.php check`; only fixed issue codes. No values or paths. Duplicate critical key names now include selected credentials and provider settings, without printing values.
- Records/media: `php app/cli-integrity.php check`; retain aggregate counts, distinguish a bounded scan from full verification. Stop affected release if mismatches appear.
- Retention: `php app/cli-retention.php report` and `php app/cli-retention.php preview 100`; no deletion. Preserve any unresolved privacy or legal hold records.
- Recovery: review the encrypted backup run, authenticated candidate verification and seven-snapshot rotation. Independent offsite custody is a distinct prerequisite and still requires approval.
- Resources: SSH diagnostics report CLI PHP upload/post/memory/time limits and underlying filesystem free-space estimate. These do not measure the account's disk/inode quota, worker processes, web PHP limits or CPU/RAM throttling. Verify hPanel metrics separately before public launch. Do not use whole-server filesystem capacity as the account's remaining allowance.

## Rollback and repair

Select a previously verified compatible release after reviewing additive migrations, current schema and immutable agreement requirements. Never roll back to a known failed intermediate SHA. Preserve original application key and approved policy files/digests. Never blindly restore an old DB over active customer records; isolate the encrypted archive, authenticate it, restore into a separate database and compare records/media/invoice decryptability. Preserve incident evidence and current backup before an authorized repair. Document any loss window, reconciliation needs and owner approval.

Do not rotate DMO_APP_KEY as a routine rollback: authentication, invoice encryption, transaction fingerprints and idempotency references depend on it. If compromised, restrict affected services and plan a specific recovery/rotation, migration and invalidation procedure. Do not log secret material.

## Resumption

Verify corrected exact release, source, disposable database and browser suites; check real provider or SMTP only within authorized pilot. Confirm configuration dependencies, historical agreements, intact backups, private media, finance reconciliation and active holds. Resume narrowly only after owner approval; intake remains paused until approved. Record the evidence and decision in launch checklist. See [controlled launch](CONTROLLED_LAUNCH_RUNBOOK.md), [recovery](INDEPENDENT_RECOVERY_MILESTONE.md), [payments](PAYMENT_OPERATIONS_GUIDE.md) and [retention](RETENTION_REVIEW_MILESTONE.md).
