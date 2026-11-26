"""Emit safe shell assignments for the exact checked-out public asset references."""
import hashlib
import pathlib
import re
import sys

ASSETS = [
    ["public_theme","portfolio.php","public-theme.js"],
    [
        "concepts",
        "index.html",
        "concepts.js"
    ],
    [
        "studio",
        "index.html",
        "studio.js"
    ],
    [
        "gallery",
        "index.html",
        "gallery.js"
    ],
    [
        "home_css",
        "index.html",
        "styles.css"
    ],
    [
        "desk_js",
        "studio-admin/index.php",
        "panel.js"
    ],
    [
        "desk_css",
        "studio-admin/index.php",
        "panel.css"
    ],
    [
        "commission_js",
        "commission.html",
        "commission.js"
    ],
    [
        "commission_css",
        "commission.html",
        "commission.css"
    ],
    [
        "customer_js",
        "account.html",
        "account.js"
    ],
    [
        "recover_js",
        "password-reset.html",
        "password-reset.js"
    ]
]

def expectations(root):
    for name, page, asset in ASSETS:
        source = (root / page).read_text(encoding="utf-8")
        pattern = r'["\'](?:\./)?(' + re.escape(asset) + r'\?v=[0-9]+)["\']'
        matches = set(re.findall(pattern, source))
        if len(matches) != 1:
            raise ValueError(f"{page}: expected one numeric version for {asset}")
        ref = matches.pop()
        path = pathlib.Path(page).parent / asset
        digest = hashlib.sha256((root / path).read_bytes()).hexdigest()
        yield name, ref, digest

# Files whose exact bytes are essential to the current release. Include
# server-side PHP and its public pages, not just browser assets.
DEPLOYMENT_FILES = [
    "app/bootstrap.php", "app/cli-config-review.php", "app/config-review.php",
    "app/cli-launch-readiness.php", "app/launch-readiness.php",
    "app/public-info.php", "app/catalog.php", "app/finance-report.php",
    "app/artist-queues.php", "app/customer-attention.php",
    "api/admin/messages.php", "api/admin/customers.php", "api/admin/requests.php",
    "api/admin/finance.php", "api/admin/privacy.php", "api/requests.php",
    "services.php", "policy.php", "portfolio.php", "artwork.php",
    "public-info.css", "studio-admin/index.php", "studio-admin/panel.js",
    "commission.html", "commission.js", "index.html", "account.html",
    "account.js", "app/privacy.php", "api/auth/privacy.php", "app/packages.php",
    "api/admin/packages.php", "api/catalog.php", "app/requests.php", "studio-admin/panel.css",
    "gallery.js", "concepts.js", "studio.js", "styles.css", "api/portfolio.php", "api/artwork.php", "app/artwork.php",
    ".htaccess", "polish.css", "branding.css", "favicon.svg", "api/health.php",
    "backend-status.php", "backend-status.html", "admin-preview.html", "commission-preview.html", "app/design.php", "api/admin/design.php", "api/site-settings.php",
    "customer-theme.css", "public-theme.js", "password-reset.html", "app/policies.php", "api/policy.php", "app/auth.php", "api/auth/login.php", "api/auth/signup.php", "api/auth/resend-verification.php", "api/auth/reset-request.php",
    "api/request.php", "api/admin/request.php", "app/quotes.php", "api/admin/quote-send.php", "api/quote-response.php", "api/admin/order-create.php", "app/notifications.php", "app/checkout.php", "api/checkout.php", "app/payments.php", "app/deliveries.php", "app/artwork-reviews.php", "api/artwork-review.php", "api/delivery.php", "app/retention.php", "app/cli-retention.php", "app/admin.php", "app/cli-admin.php", "app/references.php", "app/integrity.php",
    # Retention and private configuration review must match the current deployment.
    # Verify payment and protected download guards together.
]

def deployment_files(root):
    for name in DEPLOYMENT_FILES:
        if not re.fullmatch(r"[a-zA-Z0-9_./-]+", name) or ".." in pathlib.PurePosixPath(name).parts:
            raise ValueError("Unsafe deployment manifest path")
        yield name, hashlib.sha256((root / name).read_bytes()).hexdigest()

def main():
    root = pathlib.Path(__file__).resolve().parents[1]
    for name, ref, digest in expectations(root):
        print(f"expected_{name}='{ref}'")
        print(f"digest_{name}='{digest}'")
    print("expected_files=(")
    for path, digest in deployment_files(root):
        print(f"  '{digest}  {path}'")
    print(")")

if __name__ == "__main__":
    main()
