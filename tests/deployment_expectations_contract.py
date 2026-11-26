"""Regression checks for read-only exact-release manifests."""
import hashlib
import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "ops"))
from deployment_expectations import deployment_files, DEPLOYMENT_FILES

rows = dict(deployment_files(ROOT))
assert len(rows) == len(DEPLOYMENT_FILES) == len(set(DEPLOYMENT_FILES))
for required in ("app/cli-config-review.php", "api/admin/messages.php", "api/requests.php", "portfolio.php", "services.php", "public-info.css", "account.js", "api/auth/privacy.php", "app/packages.php", "api/admin/packages.php", "gallery.js", "concepts.js", "studio.js", "styles.css", "api/portfolio.php", "api/artwork.php", "app/artwork.php", ".htaccess", "polish.css", "branding.css", "favicon.svg", "api/health.php", "backend-status.php", "backend-status.html", "admin-preview.html", "commission-preview.html", "app/design.php", "api/admin/design.php", "api/site-settings.php", "customer-theme.css", "public-theme.js", "password-reset.html", "app/policies.php", "api/policy.php"):
    assert rows[required] == hashlib.sha256((ROOT / required).read_bytes()).hexdigest()
output = subprocess.check_output([sys.executable, str(ROOT / "ops/deployment_expectations.py")], text=True)
manifest = output.split("expected_files=(\n", 1)[1].split("\n)", 1)[0].splitlines()
assert len(manifest) == len(rows)
for entry in manifest:
    assert re.fullmatch(r"  '[0-9a-f]{64}  [A-Za-z0-9_./-]+'", entry), entry
print("DEPLOYMENT_EXPECTATIONS_CONTRACT=PASS")
