# DrawMeOne — Hostinger SSH via GitHub Actions (agent-triggered, read-only)

This reproduces the general approach used for `pixel375/canadamb`: GitHub Actions is the SSH client; the Hostinger account trusts the **public** key, while the matching **private** key is stored in GitHub Environment Secrets. The repository source contains no credentials.

This workflow is **read-only** and can be run manually or triggered by a small commit to the allowlisted `.github/ops/hostinger-ssh-diagnostic.trigger` file on `main`. The trigger file is not interpreted as a command. It never deploys files, runs database migrations, edits .env, or prints private keys, account emails, passwords or hashes.

## Workflow

`.github/workflows/hostinger-ssh-diagnostics.yml`

Go to GitHub → `pixel375/drawmeone` → **Actions** → **DrawMeOne Hostinger SSH — Read-only Diagnostics** → **Run workflow** for a manual test. After setup, ChatGPT can request a new read-only test by updating `.github/ops/hostinger-ssh-diagnostic.trigger` on `main` via the connected GitHub tool. The content of that file is only a marker; it is never evaluated as a shell command.

## Set up a separate SSH identity

Since `canadamb` already has a working SSH automation, you can replicate its key setup, but preferably generate a **separate dedicated deploy key** for DrawMeOne to reduce cross-project exposure.

On your own trusted computer, create an Ed25519 key pair. Install **only the `.pub`** contents in the Hostinger SSH account's authorized keys. Keep the private key on your computer and copy it **only** into the new repository's GitHub Environment Secret.

Do not put either the private key or GitHub secret values in this chat. Never commit the private key. The workflow expects an **unencrypted OpenSSH private key** for noninteractive execution; treat it as a high-privilege secret.

Create a GitHub Environment named `hostinger-diagnostics` under DrawMeOne repository Settings → Environments. Give it these:

Environment **variables**:
- `HOSTINGER_HOST` — verified SSH hostname/IP from Hostinger
- `HOSTINGER_PORT` — SSH port, e.g. `65002` if Hostinger says so
- `HOSTINGER_USER` — SSH username

Environment **secrets**:
- `HOSTINGER_SSH_PRIVATE_KEY` — entire corresponding private OpenSSH key text
- `HOSTINGER_KNOWN_HOSTS` — pinned host key line(s), verified against Hostinger's SSH fingerprint independently. Do not blindly trust an unverified ssh-keyscan response

The other repository's environment secrets do **not** automatically carry over to DrawMeOne. Reusing an existing key creates a shared exposure risk; a dedicated key is preferable.

## Safety and output

- Manually triggered or push-triggered **only** for the fixed diagnostic marker on `main`, with no general deploy/write operations
- Least-privilege GitHub token `contents: read`
- `StrictHostKeyChecking=yes`, explicit private key file permissions, no interactive password prompts
- Checks PHP syntax of the existing DrawMeOne authentication files
- Queries only safe aggregate booleans: database accessible, any active verified admin exists, admin flag enabled, Turnstile key pair present, PHP cURL available
- **Does not** prove the chosen login password matches or that a Turnstile challenge succeeds; use the on-server `php app/cli-admin.php check email@example.com` for the former and the actual login UI for the latter

If this workflow fails before connecting, check GitHub Environment configuration and the SSH host fingerprint. If it connects but reports `eligible_admin_present=no`, check the CLI admin creation result on the server.

Read the workflow before enabling secrets, especially if multiple people can modify the repository. A private SSH key generally grants the connected Unix account's permissions, even though the workflow itself is read-only.

## Troubleshooting the user-visible login

The Artist's Desk now displays safe, specific error categories for HTTP 401 (credentials), 422 (Cloudflare), 403 (HTTPS/origin), 429 (rate limit), and 503 (server failure). See [ADMIN_LOGIN_TROUBLESHOOTING.md](ADMIN_LOGIN_TROUBLESHOOTING.md).


## Windows PowerShell: one-time key setup

Generate a dedicated SSH key on your own Windows computer:

```powershell
New-Item -ItemType Directory -Force "$HOME\.ssh" | Out-Null
ssh-keygen -t ed25519 -f "$HOME\.ssh\drawmeone_gh_actions" -C "drawmeone-github-actions"
```

Press Enter twice at the passphrase prompts for this **automation-only** key (GitHub Actions cannot type an interactive passphrase). Store the private key only in the GitHub environment secret, not in the repo or chat.

Display the **public** key:

```powershell
Get-Content "$HOME\.ssh\drawmeone_gh_actions.pub"
```

Hostinger hPanel → Websites → Manage DrawMeOne → **SSH Access** → **Add SSH key**. Name it `DrawMeOne GitHub Actions` and paste the public key only.

Hostinger's SSH Access page lists the actual username, port, server IP and host-key fingerprints. Use these to fill environment variables (host, port, username).

Pin the SSH server identity before accepting it. From Windows PowerShell, replace `HOST` and `PORT` with the values supplied by Hostinger:

```powershell
ssh-keyscan -t ed25519 -p PORT HOST 2>$null | Set-Content -Encoding ascii "$HOME\.ssh\drawmeone_hostkeys"
ssh-keygen -lf "$HOME\.ssh\drawmeone_hostkeys"
```

Compare the shown SHA256 fingerprint to the **ED25519 host-key fingerprint displayed in Hostinger hPanel**. Do not use a mismatched host key. Add the entire matching known-hosts **line** (not just its fingerprint) to `HOSTINGER_KNOWN_HOSTS` GitHub Environment secret.

On GitHub go to `pixel375/drawmeone` → Settings → Environments → **New environment** → `hostinger-diagnostics`:

**Environment variables:** `HOSTINGER_HOST`, `HOSTINGER_PORT`, `HOSTINGER_USER`

**Environment secrets:** `HOSTINGER_SSH_PRIVATE_KEY`, `HOSTINGER_KNOWN_HOSTS`

Private key (copies to clipboard without displaying it; paste **only** into GitHub's secret-entry form):

```powershell
Get-Content -Raw "$HOME\.ssh\drawmeone_gh_actions" | Set-Clipboard
```

After a successful SSH connection test, delete the plaintext private key from unneeded locations. Keep a secure backup if you want to be able to restore the runner configuration.

**Test result:** `PHP syntax: PASS`, `db_connected=yes`, `admin_record_present=yes`, and `READ_ONLY_DIAGNOSTIC_COMPLETED` with no credentials disclosed.
