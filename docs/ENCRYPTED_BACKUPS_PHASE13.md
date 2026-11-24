# DrawMeOne v2.3 — Encrypted local Hostinger backups

Implemented November 23–24, 2026. First live verification: [GitHub Actions run 45318772324](https://github.com/pixel375/drawmeone/actions/runs/45318772324).

## Operational design

- The fixed workflow under .github/workflows/hostinger-private-backup.yml connects using pinned SSH. It runs every day at **08:17 UTC**, and accepts a fixed GitHub trigger marker for ad-hoc checks.
- The CLI app/cli-backup.php creates a consistent MySQL dump using a private temporary option file and --single-transaction, includes the private artwork and commission-reference directories when present, and packages them as a compressed tar archive.
- The archive is authenticated-encrypted in chunks using libsodium XChaCha20-Poly1305 secretstream. A full decrypt/authentication pass verifies the final tag before a snapshot is promoted.
- All archives are stored outside public_html, with private 0700 directories and 0600 files. Temporary plaintext data and connection option files are removed.
- The separate recovery key, drawmeone-backup-recovery.key, is generated in Hostinger's private configuration directory and restricted to 0600 permissions. **A copy must be preserved outside Hostinger to enable disaster recovery.** It is not printed, uploaded or stored in GitHub.
- Seven newest encrypted local snapshots are retained. A file lock prevents overlapping backups and at least 200 MB free space is required.
- The CLI command verify-latest rechecks an archive without extracting plaintext to the filesystem.

## First live verification

- encrypted_backup_created=yes
- encrypted_backup_verified=yes
- encrypted_backup_latest_verified=yes
- encrypted_backup_count=1
- offsite_backup=not_configured

This proves a locally encrypted snapshot was created and authenticated **on the same host**. It does not prove restoration into a fresh database, recoverability after a Hostinger account loss or a separately stored recovery key.

## Remaining work

1. Configure an independent encrypted off-site destination that is not the public GitHub source repository.
2. Preserve the recovery key independently of Hostinger and verify permissions/access.
3. Restore into a disposable isolated MySQL/database + private-image environment, and verify image records after extraction.
4. Monitor daily scheduled runs, available disk space and eventual privacy/retention requirements.

No customer registration, public commission intake, quote delivery or payment capabilities were activated by this phase.

## 2026-11-24 v3.5 update — Full SQL restore and safer snapshot promotion

A new required disposable CI test performs **actual separate-schema MariaDB import** and private image recovery using only synthetic records, images and a temporary encryption key. The test verifies DB-to-file correspondence and preserves the original source database.

- First complete synthetic restore passed: [CI run 45439549783](https://github.com/pixel375/drawmeone/actions/runs/45439549783).
- Corrupted candidate regression passed: [CI run 45440045053](https://github.com/pixel375/drawmeone/actions/runs/45440045053).
- Production backup CLI now tests archive recovery **before promoting a new snapshot or deleting any old snapshot**. A malformed candidate is discarded, preserving the latest known-good archive.
- The [production backup run 45440322221](https://github.com/pixel375/drawmeone/actions/runs/45440322221) confirmed `new_archive_restore_checked=yes`, `encrypted_backup_verified=yes`, `isolated_archive_restore_check=passed`, **3 locally retained snapshots** and **10 private WebP files verified**. It did not import production SQL.
- Read-only Hostinger diagnostics now require a recent private encrypted archive (under 36 hours), correct restrictive filesystem permissions and a valid-length private recovery key, **without reading or printing any secret bytes**.

**Off-site backup and separate key custody remain NOT configured.** Three validated copies on the same hosting account do not mitigate provider/account loss. Do not substitute public GitHub commits or artifacts for a private off-site backup. See [full recovery test details](FULL_RECOVERY_QA_PHASE23.md).
