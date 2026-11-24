# DrawMeOne v3.5 — Independent encrypted-backup restore drill

Implemented November 24, 2026.

## What was tested

A new required disposable CI test (`tests/full_backup_restore.php`) exercises the **actual** `app/cli-backup.php` commands with a synthetic MariaDB database and private WebP files. It uses only `drawmeone_ci` and `drawmeone_restore_ci` on a local GitHub Actions MariaDB container; it refuses any other host, user, database name, or non-CI environment.

1. Create one synthetic published artwork, the two optimized image variants, a synthetic customer request and its private reference image.
2. Invoke the real CLI `run`, `verify-latest` and `restore-check` commands, using a **fresh synthetic key** outside the webroot.
3. Decrypt the resulting encrypted snapshot with an **independent PHP sodium secretstream reader** into a private scratch folder.
4. Extract the archived SQL and image files, then import the SQL into a **different** clean MariaDB schema, not the source.
5. Assert the restored user, artwork, file and private reference records exist and that each database storage key has a corresponding recovered file.
6. Confirm the original disposable database remains unchanged and delete all temporary keys, files, SQL and images.

[First successful complete separate-schema restore](https://github.com/pixel375/drawmeone/actions/runs/45439549783).

## Safer promotion and retention

The production backup command now runs `dmo_backup_restore_check` against the **newly encrypted candidate** before promoting its name to a retained backup or deleting any of the previous seven snapshots.

This is stronger than merely testing encrypted-stream authentication: it detects malformed SQL, malicious archive entries and invalid WebP signatures before rotating out a known-good recovery point.

The CI test also corrupts a synthetic private WebP, attempts another backup and verifies that the new backup is rejected and the existing verified snapshot remains byte-for-byte unchanged. The test then restores the original synthetic image and verifies the earlier snapshot again.

The disposable integration workflow performs quick PHP syntax lint before installing MariaDB client dependencies. The separate Source Quality workflow also lints PHP tests.

## What this does not prove

This is **not** an off-site recovery drill of the current production customer's data. Hostinger remains the location of the real encrypted backup archives and the separate recovery key. There is **no verified independent off-site copy or separately stored production recovery key yet**, and therefore Hostinger account loss could still prevent disaster recovery. No new provider credentials or third-party backup transfers have been invented or enabled.

Other outstanding work includes documenting approved retention rules, independently copying encrypted archives and storing the key in a different protected location, and a controlled production-derived recovery drill only once a secure off-site destination is approved.
