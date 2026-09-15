import html
import re
import sys
import zipfile
from pathlib import Path

root = Path(sys.argv[1] if len(sys.argv) > 1 else ".").resolve()
service = (root / "lib/Service/DocumentService.php").read_text(encoding="utf-8")
errors = []
for template in (root / "resources/templates").glob("*.docx"):
    with zipfile.ZipFile(template) as archive:
        plain = ""
        for name in archive.namelist():
            if name.startswith("word/") and name.endswith(".xml"):
                plain += html.unescape(re.sub(r"<[^>]+>", "", archive.read(name).decode("utf-8", "ignore")))
    for placeholder in sorted(set(re.findall(r"\{\{.*?\}\}", plain))):
        key = placeholder[2:-2]
        covered = (
            placeholder in service
            or key.startswith("service.")
            or (key.startswith("case.") and "foreach ($m as $key => $raw)" in service)
        )
        if not covered:
            errors.append(f"{template.name}: {placeholder}")
if errors:
    raise SystemExit("Nicht angebundene Platzhalter:\n" + "\n".join(errors))
print("DOCX-Platzhaltervertrag OK")
