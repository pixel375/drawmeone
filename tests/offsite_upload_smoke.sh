#!/usr/bin/env bash
# Local-only offsite transfer test: no external account, DNS, secrets or key.
set -Eeuo pipefail
umask 077
command -v rclone >/dev/null || { echo "rclone is required for CI"; exit 1; }
tmp=$(mktemp -d)
trap 'rm -rf -- "$tmp"' EXIT
mkdir "$tmp/remote"
printf '[ci]\ntype = alias\nremote = %s/remote\n' "$tmp" > "$tmp/rclone.conf"
chmod 600 "$tmp/rclone.conf"
# Genuine synthetic authenticated ciphertext, not just a magic prefix.
# The ephemeral fixture key never goes to the simulated remote.
head -c 4096 /dev/urandom > "$tmp/plain"
php -r 'require "app/backup-archive.php"; $key=sodium_crypto_secretstream_xchacha20poly1305_keygen(); dmo_backup_encrypt($argv[1],$argv[2],$key); if(!dmo_backup_verify($argv[2],$key))exit(1); sodium_memzero($key);' "$tmp/plain" "$tmp/synthetic.bak"
rm "$tmp/plain"
chmod 600 "$tmp/synthetic.bak"
export LOCAL_ENCRYPTED_SNAPSHOT="$tmp/synthetic.bak"
export OFFSITE_RCLONE_CONFIG_PATH="$tmp/rclone.conf"
export OFFSITE_RCLONE_DEST="ci:encrypted"
export OFFSITE_OBJECT_NAME="dmo-20261124T120000Z-123.bak"

bash ops/offsite-upload.sh > "$tmp/result.txt"
grep -Fx 'offsite_encrypted_snapshot_upload=verified' "$tmp/result.txt" >/dev/null
grep -Fx 'offsite_recovery_key_transferred=no' "$tmp/result.txt" >/dev/null
cmp "$tmp/synthetic.bak" "$tmp/remote/encrypted/$OFFSITE_OBJECT_NAME" >/dev/null
echo 'PASS: encrypted-only local remote upload and full byte readback'

# Retry of exact object is safe; changed bytes under same name must fail
# without altering the previously verified copy.
bash ops/offsite-upload.sh > /dev/null
cp "$tmp/synthetic.bak" "$tmp/original.bak"
printf X | dd of="$tmp/synthetic.bak" bs=1 seek=80 count=1 conv=notrunc status=none
if cmp -s "$tmp/synthetic.bak" "$tmp/original.bak"; then
  printf Y | dd of="$tmp/synthetic.bak" bs=1 seek=80 count=1 conv=notrunc status=none
fi
if bash ops/offsite-upload.sh > /dev/null 2>&1; then
  echo 'FAIL: mismatching immutable object accepted'; exit 1
fi
cmp "$tmp/original.bak" "$tmp/remote/encrypted/$OFFSITE_OBJECT_NAME" >/dev/null
cp "$tmp/original.bak" "$tmp/synthetic.bak"
chmod 644 "$tmp/rclone.conf"
if bash ops/offsite-upload.sh > /dev/null 2>&1; then
  echo 'FAIL: readable credentials accepted'; exit 1
fi
chmod 600 "$tmp/rclone.conf"
export OFFSITE_RCLONE_DEST="ci:./encrypted"
if bash ops/offsite-upload.sh > /dev/null 2>&1; then
  echo 'FAIL: noncanonical destination accepted'; exit 1
fi
echo 'PASS: immutable collision, repeat copy, private input modes and canonical path guards'
export OFFSITE_RCLONE_DEST="ci:../../outside"
if bash ops/offsite-upload.sh > /dev/null 2>&1; then
  echo 'FAIL: path traversal destination accepted'
  exit 1
fi
export OFFSITE_RCLONE_DEST="ci:encrypted"
printf 'PLAIN!' | dd of="$tmp/synthetic.bak" bs=1 count=6 conv=notrunc status=none
if bash ops/offsite-upload.sh > /dev/null 2>&1; then
  echo 'FAIL: plaintext snapshot accepted'
  exit 1
fi
cp "$tmp/original.bak" "$tmp/synthetic.bak"
export OFFSITE_OBJECT_NAME="../../invalid.bak"
if bash ops/offsite-upload.sh > /dev/null 2>&1; then
  echo 'FAIL: unsafe destination filename accepted'
  exit 1
fi
echo 'PASS: traversal, unsafe filename and plaintext snapshots rejected'
echo 'OFFSITE_LOCAL_SMOKE=PASS'
