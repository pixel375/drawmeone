#!/usr/bin/env bash
# Only transfers an authenticated encrypted DMOBK2 snapshot. A separate
# recovery key is never present on the off-site runner or in this script.
set -Eeuo pipefail
umask 077

: "${LOCAL_ENCRYPTED_SNAPSHOT:?}"
: "${OFFSITE_RCLONE_CONFIG_PATH:?}"
: "${OFFSITE_RCLONE_DEST:?}"
: "${OFFSITE_OBJECT_NAME:?}"
command -v rclone >/dev/null || { echo "offsite_tool_unavailable"; exit 1; }
command -v sha256sum >/dev/null || exit 1

# Refuse shell fragments, URLs, absolute local paths and path traversal.
[[ "$OFFSITE_RCLONE_DEST" =~ ^[A-Za-z][A-Za-z0-9_-]*:[A-Za-z0-9_./-]+$ ]] ||
  { echo "offsite_destination_invalid"; exit 1; }
[[ "$OFFSITE_RCLONE_DEST" != *".."* &&
   "$OFFSITE_RCLONE_DEST" != *"//"* &&
   "$OFFSITE_RCLONE_DEST" != *":/"* &&
   "$OFFSITE_RCLONE_DEST" != *":." &&
   "$OFFSITE_RCLONE_DEST" != *":./"* &&
   "$OFFSITE_RCLONE_DEST" != *"/./"* &&
   "$OFFSITE_RCLONE_DEST" != *"/." ]] ||
  { echo "offsite_destination_unsafe"; exit 1; }
[[ "$OFFSITE_OBJECT_NAME" =~ ^dmo-[0-9]{8}T[0-9]{6}Z-[0-9]+\.bak$ ]] ||
  { echo "offsite_object_name_invalid"; exit 1; }
[[ -f "$LOCAL_ENCRYPTED_SNAPSHOT" &&
   ! -L "$LOCAL_ENCRYPTED_SNAPSHOT" &&
   -f "$OFFSITE_RCLONE_CONFIG_PATH" &&
   ! -L "$OFFSITE_RCLONE_CONFIG_PATH" ]] ||
  { echo "offsite_local_inputs_invalid"; exit 1; }

# The runner must keep ciphertext and credentials private and distinct.
for file in "$LOCAL_ENCRYPTED_SNAPSHOT" "$OFFSITE_RCLONE_CONFIG_PATH"; do
  mode=$(stat -c '%a' "$file")
  [[ "$mode" == 600 || "$mode" == 400 ]] || { echo "offsite_local_permissions_unsafe"; exit 1; }
done
[[ ! "$LOCAL_ENCRYPTED_SNAPSHOT" -ef "$OFFSITE_RCLONE_CONFIG_PATH" ]] ||
  { echo "offsite_local_inputs_not_distinct"; exit 1; }

# The first six bytes identify the authenticated-encryption frame format,
# not a plaintext SQL dump. The source CLI already fully authenticates it.
magic=$(head -c 6 "$LOCAL_ENCRYPTED_SNAPSHOT")
[[ "$magic" == "DMOBK2" ]] ||
  { echo "offsite_unencrypted_or_unknown_snapshot"; exit 1; }
localBytes=$(stat -c '%s' "$LOCAL_ENCRYPTED_SNAPSHOT")
[[ "$localBytes" =~ ^[0-9]+$ ]] && (( localBytes > 256 )) ||
  { echo "offsite_snapshot_too_small"; exit 1; }

destination="${OFFSITE_RCLONE_DEST%/}/${OFFSITE_OBJECT_NAME}"
rclone --config "$OFFSITE_RCLONE_CONFIG_PATH" --quiet copyto \
  --immutable --retries 3 --low-level-retries 3 \
  "$LOCAL_ENCRYPTED_SNAPSHOT" "$destination" 2>/dev/null ||
  { echo "offsite_immutable_upload_failed"; exit 1; }
localDigest=$(sha256sum "$LOCAL_ENCRYPTED_SNAPSHOT" | cut -d ' ' -f 1)
remoteDigest=$(rclone --config "$OFFSITE_RCLONE_CONFIG_PATH" --quiet \
  cat --retries 3 "$destination" 2>/dev/null | sha256sum | cut -d ' ' -f 1) ||
  { echo "offsite_remote_readback_failed"; exit 1; }
[[ "$localDigest" == "$remoteDigest" ]] ||
  { echo "offsite_verification_failed"; exit 1; }
echo "offsite_encrypted_snapshot_upload=verified"
echo "offsite_recovery_key_transferred=no"
echo "offsite_remote_retention=not_managed"
