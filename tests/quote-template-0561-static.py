from pathlib import Path
import re
import sys
import zipfile


root = Path(sys.argv[1] if len(sys.argv) > 1 else ".").resolve()
service = (root / "lib/Service/DocumentService.php").read_text(encoding="utf-8")
template = root / "resources/templates/BESTATTUNGSAUFTRAG.docx"

for marker in (
    "Eigene Leistungen – verbindliche Angebotspreise",
    "Voraussichtliche Fremdleistungen und Fremdkosten",
    "Voraussichtliche Gebühren und durchlaufende Posten",
    "wesentliche Kostenabweichung",
    "Voraussichtlicher Gesamtbetrag",
    "REPLACEABLE_STANDARD_TEMPLATE_HASHES",
    "2aba502e61c9ee18af3be3da0926f903644cb1514e22f6fe614fea5f367792ad",
):
    assert marker in service, f"KVA-Dokumentlogik fehlt: {marker}"

with zipfile.ZipFile(template) as archive:
    xml = archive.read("word/document.xml").decode("utf-8")
    text = re.sub(r"<[^>]+>", "", xml)

for placeholder in (
    "{{service.group_heading}}", "{{service.group_subtotal}}", "{{service.group_subtotal_amount}}",
    "{{tax.rate}}", "{{tax.net}}", "{{tax.vat}}", "{{order.cost_note}}", "{{order.total_gross_label}}",
):
    assert placeholder in text, f"KVA-Vorlage enthält {placeholder} nicht"

print("quote-template-0561-static: ok")
