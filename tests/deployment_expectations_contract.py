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
for required in ("index.html","studio.js","styles.css","privacy.php","studio-admin/index.php","studio-admin/panel.js","studio-admin/panel.css","app/studio-v2.php","api/studio.php","api/admin/studio.php","app/guest-requests.php","api/guest-request.php","api/admin/guest-requests.php",".htaccess","api/.htaccess","api/admin/.htaccess","api/auth/.htaccess","api/portfolio.php","api/artwork.php","app/artwork.php","app/admin.php","app/auth.php","app/bootstrap.php"):
    assert rows[required] == hashlib.sha256((ROOT / required).read_bytes()).hexdigest()
output = subprocess.check_output([sys.executable, str(ROOT / "ops/deployment_expectations.py")], text=True)
manifest = output.split("expected_files=(\n", 1)[1].split("\n)", 1)[0].splitlines()
assert len(manifest) == len(rows)
for entry in manifest:
    assert re.fullmatch(r"  '[0-9a-f]{64}  [A-Za-z0-9_./-]+'", entry), entry
print("DEPLOYMENT_EXPECTATIONS_CONTRACT=PASS")
