# v3.6 — Backup monitoring and independent-copy preparation

Implemented November 24, 2026. These changes **do not** enable public customer registration, commissions, or payments, and do **not** export a production recovery key.

## Automated operational monitoring

- `.github/workflows/backup-failure-alert.yml`: triggered when a daily encrypted Hostinger backup workflow finishes. If the latest completed run failed, it creates or updates one deduplicated GitHub issue containing only the workflow link/status. After the latest completed backup succeeds, it closes the warning with a recovery comment. Out-of-order older workflow events are ignored. No private paths, keys, SQL, or customer data are copied into issues.
- `.github/workflows/hostinger-ssh-diagnostics.yml` now also runs daily at **12:47 UTC**, after the existing 08:17 UTC daily backup, in addition to explicit read-only trigger checks.
- New `app/backup-health.php` centralizes metadata-only checks for the Artist Desk readiness dashboard: a recent (under 36 hours) encrypted snapshot, restrictive permissions on the private backup directory, snapshots, and key, valid recovery-key length, no symlinked snapshot, and safe non-webroot storage. The dashboard never exposes filenames or secrets.
- New `tests/backup_health_contract.php` checks missing, stale, exposed, symlinked, and invalid-key fixtures. [Source Quality passed](https://github.com/pixel375/drawmeone/actions/runs/45444053146).

## Off-site data transfer **prepared, not activated**

- A new CLI action `php app/cli-backup.php export-latest` verifies the newest recent archive and streams **only authenticated ciphertext bytes** to stdout. Its byte-for-byte export and refusal of stale snapshots passed the disposable [full MariaDB restore CI run](https://github.com/pixel375/drawmeone/actions/runs/45444471510).
- `ops/offsite-upload.sh` uses a separately configured rclone destination, checks the DMOBK2 encrypted format, prevents path traversal and unexpected destination names, copies without silent overwrite, and checks the full remote bytes by readback SHA-256. It never loads or transports the decryption key.
- `.github/workflows/offsite-encrypted-copy.yml` is intentionally **manual-only** until the owner approves an independent provider and its restricted credentials are added to the Hostinger diagnostics environment. It fails closed when configuration is missing; it is not scheduled and has not been used with production data.
- `tests/offsite_upload_smoke.sh` simulates rclone with an ephemeral **local-only alias backend**, not an external service; it checks exact byte-for-byte remote readback and rejects unencrypted blobs, unsafe paths and filenames. [Transfer QA passed](https://github.com/pixel375/drawmeone/actions/runs/45444968601).
- `DMO_OFFSITE_RCLONE_CONFIG` is the proposed GitHub environment **secret**, and `DMO_OFFSITE_RCLONE_DEST` is the environment **variable**. Neither is provisioned here. Provider selection/approval and independent key custody remain required.

## Reality of recovery readiness

An encrypted backup stored elsewhere is only useful if the separately protected recovery key also survives Hostinger loss. **Current status: local backup verified; independent off-site archive NOT configured; separately held production recovery key NOT confirmed.** This remains [launch blocker #1](https://github.com/pixel375/drawmeone/issues/1).

No backup bytes, customer records, recovery keys or off-site provider credentials have been added to GitHub code, Issues or artifacts. The validated local Hostinger backups and seven-copy retention remain unchanged.

## Production verification — November 24

The upgraded Hostinger backup command completed successfully in [run 45445427769](https://github.com/pixel375/drawmeone/actions/runs/45445427769): it verified a newly encrypted snapshot, performed the pre-promotion isolated recovery check, retained **4** local encrypted snapshots and inspected **10** private WebP files. `offsite_backup=not_configured` remains explicit.

The newly registered [backup-incident monitor run 45445471845](https://github.com/pixel375/drawmeone/actions/runs/45445471845) completed with `backup_alert=healthy` following this successful production backup. A real failure was **not** induced against production; failure-side issue creation is implemented but not exercised with real production outages.
