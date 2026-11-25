"""Emit safe shell assignments for the exact checked-out public asset references."""
import hashlib
import pathlib
import re
import sys

ASSETS = [
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

def main():
    root = pathlib.Path(__file__).resolve().parents[1]
    for name, ref, digest in expectations(root):
        print(f"expected_{name}='{ref}'")
        print(f"digest_{name}='{digest}'")

if __name__ == "__main__":
    main()
