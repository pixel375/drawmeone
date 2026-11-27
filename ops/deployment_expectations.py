"""Exact deployed V2 public, admin, route and shared security file digests."""
import hashlib
import pathlib
import re

DEPLOYMENT_FILES = [
    "index.html","styles.css","studio.js","privacy.php","favicon.svg",
    "portfolio.php","artwork.php","sitemap.php","robots.txt","public-theme.js",
    ".htaccess","api/.htaccess","api/admin/.htaccess","api/auth/.htaccess",
    "studio-admin/index.php","studio-admin/panel.css","studio-admin/panel.js",
    "app/studio-v2.php","api/studio.php","api/admin/studio.php","app/guest-requests.php","api/guest-request.php","api/admin/guest-requests.php","app/cli-migrate-guest.php","database/007_guest_requests.sql",
    "api/portfolio.php","api/artwork.php","api/admin/artworks.php","api/admin/artwork-upload.php",
    "api/admin/artwork-preview.php","app/artwork.php","app/admin.php","app/auth.php","app/bootstrap.php",
    "api/admin/login.php","api/admin/status.php","api/admin/me.php","api/admin/logout.php",
    "app/cli-admin.php","app/mailer.php","app/cli-integrity.php","app/backup-health.php",
]
def deployment_files(root):
    for name in DEPLOYMENT_FILES:
        if not re.fullmatch(r"[a-zA-Z0-9_./-]+",name) or ".." in pathlib.PurePosixPath(name).parts:
            raise ValueError("Unsafe deployment path")
        yield name,hashlib.sha256((root/name).read_bytes()).hexdigest()
def main():
    root=pathlib.Path(__file__).resolve().parents[1]
    print("expected_files=(")
    for path,digest in deployment_files(root):
        print(f"  '{digest}  {path}'")
    print(")")
if __name__=="__main__":
    main()
