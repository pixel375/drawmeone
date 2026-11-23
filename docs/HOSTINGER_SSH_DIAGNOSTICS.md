# DrawMeOne — Hostinger SSH via GitHub Actions (manual diagnostics)

This reproduces the general approach used for `pixel375/canadamb`: GitHub Actions is the SSH client; the Hostinger account trusts the **public** key, while the matching **private** key is stored in GitHub Environment Secrets. The repository source contains no credentials.

This workflow is **manual-only and read-only**. It never deploys files, runs database migrations, edits .env, or prints private keys, account emails, passwords or hashes.

## Workflow

`.github/workflows/hostinger-ssh-diagnostics.yml`

Go to GitHub → `pixel375/drawmeone` → **Actions** → **DrawMeOne Hostinger SSH — Read-only Diagnostics** → **Run workflow**, only after configuration below.

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

- Manual `workflow_dispatch` only, no push or automatic triggers
- Least-privilege GitHub token `contents: read`
- `StrictHostKeyChecking=yes`, explicit private key file permissions, no interactive password prompts
- Checks PHP syntax of the existing DrawMeOne authentication files
- Queries only safe aggregate booleans: database accessible, any active verified admin exists, admin flag enabled, Turnstile key pair present, PHP cURL available
- **Does not** prove the chosen login password matches or that a Turnstile challenge succeeds; use the on-server `php app/cli-admin.php check email@example.com` for the former and the actual login UI for the latter

If this workflow fails before connecting, check GitHub Environment configuration and the SSH host fingerprint. If it connects but reports `eligible_admin_present=no`, check the CLI admin creation result on the server.

Read the workflow before enabling secrets, especially if multiple people can modify the repository. A private SSH key generally grants the connected Unix account's permissions, even though the workflow itself is read-only.

## Troubleshooting the user-visible login

The Artist's Desk now displays safe, specific error categories for HTTP 401 (credentials), 422 (Cloudflare), 403 (HTTPS/origin), 429 (rate limit), and 503 (server failure). See [ADMIN_LOGIN_TROUBLESHOOTING.md](ADMIN_LOGIN_TROUBLESHOOTING.md).
