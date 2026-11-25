# Commit-derived live deployment checks

The read-only diagnostic previously pinned asset versions in its workflow, causing a false failure when panel.js changed from 61 to 65. It now checks out the triggering commit, derives strictly numeric version references from the committed page sources, and verifies exact SHA-256 digests of ten public static assets fetched over HTTPS. Existing feature and protected-endpoint checks remain. No production writes or new credentials are involved. A superseded commit may fail if a newer release has already replaced it; inspect the current main run.
