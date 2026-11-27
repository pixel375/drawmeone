"""Verify the complete local logo pair library and safe public fallback assets."""
from pathlib import Path
import re
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
NS = "{http://www.w3.org/2000/svg}"
slugs = ["01-minimal-pencil", "02-signature", "03-dmo-monogram", "04-portrait-seal", "05-editorial", "06-brushstroke", "07-portrait", "08-paintbrush-badge", "09-pen-lettering", "10-creative-palette"]

def inspect(path):
    root = ET.parse(path).getroot()
    assert root.tag == NS+"svg", path
    values = list(map(float,root.attrib["viewBox"].split()))
    assert len(values)==4 and min(values[2:])>0, path
    for item in root.iter():
        assert item.tag in {NS+name for name in ("svg","g","path","rect")}, (path,item.tag)
        for key,value in item.attrib.items():
            assert not key.lower().startswith("on") and not key.endswith("href") and "url(" not in value.lower(), path
    return root

for slug in slugs:
    black=inspect(ROOT/"assets"/"branding"/(slug+"-black.svg"))
    white=inspect(ROOT/"assets"/"branding"/(slug+"-white.svg"))
    assert black.attrib["viewBox"]==white.attrib["viewBox"], slug
    bp=black.findall(".//"+NS+"path"); wp=white.findall(".//"+NS+"path")
    assert bp and len(bp)==len(wp),slug
    for b,w in zip(bp,wp):
        assert b.attrib.get("fill")=="#000" and w.attrib.get("fill")=="#fff",slug
        assert {k:v for k,v in b.attrib.items() if k!="fill"}=={k:v for k,v in w.attrib.items() if k!="fill"},slug
inspect(ROOT/"favicon.svg")
html=(ROOT/"index.html").read_text(encoding="utf-8")
assert not re.search(r'<meta[^>]+property="og:image"[^>]+assets/demo/',html), "No sample social image"
assert "Design Lab" not in html and "assets/demo/" not in html and "drawmeone" in html
print("BRANDING_CONTRACT=PASS")
