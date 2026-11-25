# Independent recovery workstation preparation

This milestone supplies a disconnected archive-verification CLI, without requiring the Hostinger environment, application bootstrap, network, or database credentials. It does not configure storage or transfer a production backup or key.

## Before custody setup

Choose a private, independent ciphertext destination and a separate protected location for the binary backup recovery key. Preserve the original **DMO_APP_KEY** separately as well: hosted-invoice ciphertext uses a key derived from it; the backup recovery key alone does not decrypt invoice URLs. Preserve the approved immutable policy bundles and their digest pins, deployed source revision, required private configuration and storage layout. Inventory SMTP/CAPTCHA/provider credentials for secure reconfiguration. Do not commit any of these secrets to source control, put them in workflow logs, or store keys alongside the remote ciphertext.

## Offline verification (Linux, PHP 8.2+ with sodium, GNU tar)

Use a trusted workstation with encrypted local storage, not a public webroot, and enough free space for decrypted SQL and images. Retrieve the ciphertext and recovery key using independently authorized channels. Keep both files mode 0600 and use an empty mode 0700 scratch directory.

```sh
umask 077
mkdir -m 700 /private/recovery-scratch
php app/cli-recovery-check.php /private/snapshot.bak /separate/key-file /private/recovery-scratch
```

Use actual local paths; do not paste key contents into a shell, chat, or command arguments. The key file is the original binary 32-byte file, not its hexadecimal or Base64 representation.

Expected: offline_archive_check=passed, image and directory counts, sql_import_attempted=no. This checks authenticated encryption including the final frame, rejects trailing bytes and unsafe archive names/types/duplicates, checks the SQL schema marker and WebP signatures, and removes temporary extracted files on ordinary success/failure. It does not prove SQL importability, database-to-media completeness or decryption of application-level ciphertext. Unexpected interruption can leave plaintext scratch; inspect and remove it securely before leaving the workstation. The CLI does not import SQL or modify a live site.

## Independent full restore drill

Only after independent custody is approved, recover into a fresh isolated database and private media roots on a disconnected recovery host, with outbound mail/payment integrations disabled and all commercial gates closed. Never restore directly over production. Validate schema migrations including checkout 006, row/media correspondence, delivery hashes, historical policy digests, and application-level ciphertext access using the original app key. Revoke restored login sessions and one-time auth tokens before any network access; use fresh operational credentials where possible. Record snapshot identity, UTC verification time, source revision, recovery elapsed time, and aggregate outcomes without customer data or secret values.

The existing disposable CI full SQL restore remains the synthetic end-to-end test. The new CLI is exercised against its exact exported ciphertext, including wrong-key, truncated, appended, symlink and unsafe scratch cases. No production-derived independent recovery has yet occurred.


The synthetic full SQL restore now includes an actual application-encrypted invoice; it verifies authentication with the original separately retained application key, rejection with a wrong key or order binding, and absence of raw URL/key in the SQL dump. This is not production off-site custody evidence.
