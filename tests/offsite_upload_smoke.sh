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
printf 'DMOBK2' > "$tmp/synthetic.bak"
head -c 1024 /dev/urandom >> "$tmp/synthetic.bak"
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
echo 'PASS: traversal and plaintext snapshots rejected'
echo 'OFFSITE_LOCAL_SMOKE=PASS'
