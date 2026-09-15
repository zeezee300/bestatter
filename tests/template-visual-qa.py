from __future__ import annotations

import argparse
import copy
import csv
import hashlib
import html
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path

from lxml import etree
from PIL import Image, ImageChops, ImageStat
from pypdf import PdfReader

W = "http://schemas.openxmlformats.org/wordprocessingml/2006/main"
NS = {"w": W}
PLACEHOLDER = re.compile(r"\{\{([^{}]+)\}\}")
EMPTY_KEYS = {"case.religion_disclosure", "case.death_time_from", "case.death_time_to", "case.partnership_date", "case.divorce_date"}

CASE_VALUES = {
    "id": "2026-0042", "case_number": "2026-0042", "number": "2026-0042", "salutation": "Frau", "title": "Dr.",
    "first_name": "Helene", "additional_first_names": "Maria Elise", "last_name": "Müller", "birth_name": "Groß",
    "date_of_birth": "12.03.1942", "birth_place": "Bremen", "birth_registry_office": "Standesamt Bremen-Mitte",
    "date_of_death": "01.09.2026, 14:35 Uhr", "time_of_death": "14:35", "place_of_death": "Klinikum Bremen-Mitte",
    "last_residence": "Große Straße 12, 28195 Bremen, Deutschland", "last_residence_street": "Große Straße 12",
    "last_residence_postal_code": "28195", "last_residence_city": "Bremen", "last_residence_country": "Deutschland",
    "gender": "weiblich", "civil_status": "verheiratet", "religion": "evangelisch", "religion_disclosure": "",
    "spouse_first_name": "Jürgen", "spouse_last_name": "Müller", "spouse_date_of_birth": "08.11.1940",
    "spouse_birth_place": "Bremerhaven", "spouse_residence": "Große Straße 12, 28195 Bremen",
    "spouse_date_of_death": "", "spouse_death_place": "", "marriage_date": "20.06.1964", "marriage_place": "Bremen",
    "partnership_date": "", "divorce_date": "", "funeral_type": "Erdbestattung", "cemetery": "Riensberger Friedhof",
    "cemetery_contact": "Riensberger Friedhof", "grave_number": "A-17-42", "grave_type": "Wahlgrab",
    "responsible_employee": "Bestatter-Administration", "branch": "STAMMHAUS", "branch_name": "Musterbestattungen Bremen GmbH",
    "branch_address": "Am Wall 100, 28195 Bremen", "branch_phone": "+49 421 123456", "branch_email": "kontakt@musterbestattungen.example",
    "branch_city": "Bremen", "branch_vat_id": "DE123456789", "branch_tax_number": "60/123/45678",
    "branch_register": "HRB 12345 HB", "branch_managing_directors": "Anna Beispiel", "current_date": "05.09.2026",
    "recipient_name": "Standesamt Bremen-Mitte", "recipient_address": "Hollerallee 79, 28209 Bremen",
    "order_client_first_name": "Anna", "order_client_name": "Müller", "order_client_relation": "Tochter",
    "order_client_street": "Parkallee 25", "order_client_postal_city": "28209 Bremen", "order_client_country": "Deutschland",
    "order_client_phone": "+49 421 987654", "order_client_mobile": "+49 170 1234567", "order_client_email": "anna.mueller@example.org",
    "invoice_number": "RE-2026-000042", "invoice_type_label": "Schlussrechnung", "invoice_sequence": "2",
    "invoice_recipient_name": "Anna Müller", "invoice_recipient_address": "Parkallee 25, 28209 Bremen",
    "invoice_date": "05.09.2026", "invoice_due_date": "19.09.2026", "invoice_prior_gross": "500,00 EUR",
    "invoice_service_period": "02.09.2026–05.09.2026",
    "invoice_payment_reference": "Rechnung RE-2026-000042", "invoice_payment_instruction": "Bitte überweisen Sie 1.555,15 EUR bis zum 19.09.2026.",
    "invoice_payment_qr_label": "SEPA-ZAHLCODE",
    "bank_account_holder": "Musterbestattungen Bremen GmbH", "bank_name": "Sparkasse Bremen", "bank_iban": "DE02 1203 0000 0000 2020 51", "bank_bic": "BFSWDE33XXX",
    "broadcast_contribution_number": "123 456 789", "contract_reference": "V-2026-4711", "bank_customer_reference": "K-876543",
    "employer_reference": "Musterwerke GmbH / P-4711", "pension_insurance_number": "12 150442 M 001",
    "certificate_free_count": "2", "certificate_paid_count": "3", "urkunden_gebuehrenpflichtig": "3",
    "registry_office": "Standesamt Bremen-Mitte", "registry_reference": "S 1234/2026", "birth_registry_reference": "G 4321/1942",
    "marriage_registry_reference": "E 234/1964", "partnership_registry_reference": "", "registry_documents": "Sterbeurkunde, Personalausweis",
    "order_number": "AU-2026-0042", "order_date": "02.09.2026", "kva_number": "KVA-2026-0042", "kva_date": "02.09.2026",
    "order_status": "Beauftragt", "commissioning_type": "Bestattungsauftrag", "order_signature_name": "Anna Müller", "order_signature_date": "02.09.2026",
}

OTHER_VALUES = {
    "deceased_name": "Helene Müller", "branch.name": "Musterbestattungen Bremen GmbH", "standesamt": "Standesamt Bremen-Mitte",
    "religion": "evangelisch", "todesort_anschrift": "Klinikum Bremen-Mitte, St.-Jürgen-Straße 1, 28205 Bremen",
    "minderjaehrige_kinder": "keine", "eingang_am": "05.09.2026", "sterberegister_nr": "S 1234/2026",
    "geburtsregister_hinweis": "G 4321/1942", "ehe_register_hinweis": "E 234/1964", "lebenspartnerschaft_register_hinweis": "",
    "nachweise": "Sterbeurkunde, Personalausweis", "pruefvermerk": "Angaben anhand der Unterlagen geprüft.",
    "anzeigende_person": "Anna Müller", "anzeigende_anschrift": "Parkallee 25, 28209 Bremen", "auftrag_telefon": "+49 170 1234567",
    "urkunden_gebuehrenfrei": "2", "urkunden_gebuehrenpflichtig": "3", "ort_datum": "Bremen, 05.09.2026",
    "funeral_event.date": "10.09.2026", "funeral_event.date_iso": "2026-09-10", "funeral_event.time": "11:00",
    "funeral_event.end_time": "12:30", "funeral_event.location": "Kapelle Riensberger Friedhof",
    "funeral_event.category": "1. Trauerfeier", "funeral_event.external_participants": "Pastorin Dr. Käthe Weiß; Friedhof Bremen",
    "order.document_title": "Bestattungsauftrag", "order.confirmation_text": "Die abgestimmten Leistungen werden verbindlich beauftragt.",
    "order.status": "Beauftragt", "order.workflow_detail": "Persönlich erteilt", "order.kva_number": "AU-2026-0042",
    "order.kva_date": "02.09.2026", "order.order_client_name": "Anna Müller", "order.order_client_relation": "Tochter",
    "order.order_client_phone": "+49 421 987654", "order.order_client_mobile": "+49 170 1234567",
    "order.order_client_email": "anna.mueller@example.org", "order.invoice_recipient_address": "Anna Müller, Parkallee 25, 28209 Bremen",
    "order.certificate_free_count": "2", "order.order_signature_name": "Anna Müller", "order.order_signature_date": "02.09.2026",
    "order.total_net": "1.319,50 EUR", "order.taxable_net": "1.283,50 EUR", "order.total_vat": "235,65 EUR",
    "order.pass_through_total": "36,00 EUR", "order.total_gross": "1.555,15 EUR",
    "order.services_heading": "Beauftragte Leistungen und Kosten",
    "order.cost_note": "Die Einzelpreise verstehen sich netto. Die ausgewiesene Gesamtsumme enthält die gesetzliche Mehrwertsteuer. Fremdleistungen, Auslagen und Gebühren werden entsprechend ihrer vertraglichen und steuerlichen Behandlung dargestellt.",
    "order.tax_heading": "Umsatzsteuerübersicht", "order.taxable_net_label": "Steuerpflichtiges Entgelt netto",
    "order.total_vat_label": "Mehrwertsteuer", "order.pass_through_label": "Durchlaufende Posten", "order.total_gross_label": "Gesamtbetrag",
}

SERVICES = [
    (10, "Beratung und Organisation", "1 STK", "250,00 EUR", "19 %", "297,50 EUR", "EL"),
    (20, "Überführung innerhalb Bremens", "1 STK", "320,00 EUR", "19 %", "380,80 EUR", "EL"),
    (30, "Versorgung und Einkleidung", "1 STK", "185,00 EUR", "19 %", "220,15 EUR", "EL"),
    (40, "Eichensarg Modell Würde", "1 STK", "460,00 EUR", "19 %", "547,40 EUR", "FK"),
    (50, "Trauerhallendekoration weiß und grün", "1 STK", "68,50 EUR", "7 %", "73,30 EUR", "FK"),
    (60, "Verauslagte Friedhofsgebühr", "1 STK", "36,00 EUR", "0 %", "36,00 EUR", "DP"),
]

GROUP_LABELS = {
    "EL": "Eigene Leistungen",
    "FK": "Fremdleistungen und verauslagte Beträge",
    "DP": "Durchlaufende Posten – nicht Teil des Entgelts",
}
QUOTE_GROUP_LABELS = {
    "EL": "Eigene Leistungen – verbindliche Angebotspreise",
    "FK": "Voraussichtliche Fremdleistungen und Fremdkosten",
    "DP": "Voraussichtliche Gebühren und durchlaufende Posten",
}
GROUP_TOTALS = {"EL": "898,45 EUR", "FK": "620,70 EUR", "DP": "36,00 EUR"}
TAX_ROWS = [("19 %", "1.215,00 EUR", "230,85 EUR"), ("7 %", "68,50 EUR", "4,80 EUR")]


def text_nodes(container: etree._Element, include_instructions: bool = False) -> list[etree._Element]:
    xpath = ".//w:t" + (" | .//w:instrText" if include_instructions else "")
    return list(container.xpath(xpath, namespaces=NS))


def joined_text(container: etree._Element, include_instructions: bool = False) -> str:
    return "".join(node.text or "" for node in text_nodes(container, include_instructions))


def replace_range(nodes: list[etree._Element], start: int, length: int, replacement: str) -> None:
    offsets, position = [], 0
    for node in nodes:
        value = node.text or ""
        offsets.append((position, position + len(value)))
        position += len(value)
    end = start + length
    first = next(i for i, (_, stop) in enumerate(offsets) if stop > start)
    last = next(i for i in range(first, len(offsets)) if offsets[i][1] >= end)
    first_start, _ = offsets[first]
    last_start, _ = offsets[last]
    prefix = (nodes[first].text or "")[: start - first_start]
    suffix = (nodes[last].text or "")[end - last_start :]
    nodes[first].text = prefix + replacement + suffix
    for index in range(first + 1, last + 1):
        nodes[index].text = ""


def replace_placeholders(container: etree._Element, values: dict[str, str]) -> None:
    paragraphs = container.xpath(".//w:p", namespaces=NS)
    if container.tag == f"{{{W}}}p":
        paragraphs = [container]
    for paragraph in paragraphs:
        nodes = text_nodes(paragraph)
        text = "".join(node.text or "" for node in nodes)
        for match in reversed(list(PLACEHOLDER.finditer(text))):
            key = match.group(1).split("|", 1)[0]
            replace_range(nodes, match.start(), len(match.group(0)), values.get(key, ""))


def expand_service_rows(document: etree._Element, values: dict[str, str]) -> None:
    rows = list(document.xpath(".//w:tr", namespaces=NS))
    heading = next((row for row in rows if "{{service.group_heading}}" in joined_text(row)), None)
    item = next((row for row in rows if "{{service.title}}" in joined_text(row)), None)
    subtotal = next((row for row in rows if "{{service.group_subtotal}}" in joined_text(row)), None)
    if heading is not None and item is not None and subtotal is not None:
        parent, index = heading.getparent(), heading.getparent().index(heading)
        offset = 0
        for position_type in ("EL", "FK", "DP"):
            group = [service for service in SERVICES if service[6] == position_type]
            if not group:
                continue
            clone = copy.deepcopy(heading)
            labels = QUOTE_GROUP_LABELS if values.get("order.document_title") == "Kostenvoranschlag" else GROUP_LABELS
            replace_placeholders(clone, values | {"service.group_heading": labels[position_type]})
            parent.insert(index + offset, clone)
            offset += 1
            for service in group:
                clone = copy.deepcopy(item)
                service_values = {
                    "service.position": str(service[0]), "service.title": service[1], "service.quantity": service[2],
                    "service.unit_price": service[3], "service.vat_rate": service[4], "service.gross": service[5],
                }
                replace_placeholders(clone, values | service_values)
                parent.insert(index + offset, clone)
                offset += 1
            clone = copy.deepcopy(subtotal)
            replace_placeholders(clone, values | {
                "service.group_subtotal": f"Zwischensumme {labels[position_type]}",
                "service.group_subtotal_amount": GROUP_TOTALS[position_type],
            })
            parent.insert(index + offset, clone)
            offset += 1
        for prototype in (heading, item, subtotal):
            parent.remove(prototype)
    else:
        for row in rows:
            if "{{service." not in joined_text(row):
                continue
            parent, index = row.getparent(), row.getparent().index(row)
            parent.remove(row)
            for offset, service in enumerate(SERVICES):
                clone = copy.deepcopy(row)
                service_values = {
                    "service.position": str(service[0]), "service.title": service[1], "service.quantity": service[2],
                    "service.unit_price": service[3], "service.vat_rate": service[4], "service.gross": service[5],
                }
                replace_placeholders(clone, values | service_values)
                parent.insert(index + offset, clone)

    for row in list(document.xpath(".//w:tr", namespaces=NS)):
        if "{{tax.rate}}" not in joined_text(row):
            continue
        parent, index = row.getparent(), row.getparent().index(row)
        parent.remove(row)
        for offset, tax in enumerate(TAX_ROWS):
            clone = copy.deepcopy(row)
            replace_placeholders(clone, values | {"tax.rate": tax[0], "tax.net": tax[1], "tax.vat": tax[2]})
            parent.insert(index + offset, clone)


def remove_empty_invoice_summary_rows(document: etree._Element, values: dict[str, str]) -> None:
    if values.get("case.invoice_sequence", "1") not in {"", "0", "1"}:
        return
    for row in list(document.xpath(".//w:tr", namespaces=NS)):
        if "{{case.invoice_prior_gross}}" in joined_text(row):
            row.getparent().remove(row)


def source_placeholders(xml_entries: dict[str, bytes]) -> set[str]:
    found: set[str] = set()
    for name, content in xml_entries.items():
        if not name.startswith("word/") or not name.endswith(".xml"):
            continue
        root = etree.fromstring(content)
        for paragraph in root.xpath(".//w:p", namespaces=NS):
            found.update(match.group(1).split("|", 1)[0] for match in PLACEHOLDER.finditer(joined_text(paragraph, True)))
    return found


def catalog_placeholders(root: Path, schema: list[dict]) -> set[str]:
    catalog = {f"case.{entry['key']}" for entry in schema}
    for source in [
        root / "lib/Service/DocumentService.php",
        root / "lib/Service/DeregistrationService.php",
        root / "lib/Service/WorkflowService.php",
        root / "lib/Service/TemplateFieldCatalogService.php",
    ]:
        catalog.update(match.group(1).split("|", 1)[0] for match in PLACEHOLDER.finditer(source.read_text(encoding="utf-8")))
    for source in (root / "resources/templates").glob("*.txt"):
        catalog.update(match.group(1).split("|", 1)[0] for match in PLACEHOLDER.finditer(source.read_text(encoding="utf-8")))
    for source in (root / "resources/templates").glob("*.docx"):
        catalog.update(source_placeholders(zipfile_entries(source)))
    return catalog


def build_values(root: Path) -> tuple[dict[str, str], set[str]]:
    schema = json.loads((root / "resources/case-field-schema.json").read_text(encoding="utf-8"))
    values = {f"case.{entry['key']}": ("01.09.2026" if "date" in entry["key"] else f"Musterangabe {entry['label']}") for entry in schema}
    values.update({f"case.{key}": value for key, value in CASE_VALUES.items()})
    values.update(OTHER_VALUES)
    for key in EMPTY_KEYS:
        values[key] = ""
    return values, catalog_placeholders(root, schema)


def validate_catalog(placeholders: set[str], catalog: set[str]) -> list[str]:
    errors = []
    for key in sorted(placeholders):
        if key not in catalog and key != "…":
            errors.append(f"Nicht im vollständigen Feldkatalog: {key}")
    return errors


def materialize(source: Path, target: Path, values: dict[str, str], qr_path: Path, placeholder_qr: Path) -> tuple[set[str], bool]:
    with zipfile.ZipFile(source) as archive:
        entries = {name: archive.read(name) for name in archive.namelist()}
    placeholders = source_placeholders(entries)
    if not placeholders:
        raise RuntimeError(f"{source.name}: keine lesbaren Platzhalter gefunden")
    for name, content in list(entries.items()):
        if not name.startswith("word/") or not name.endswith(".xml"):
            continue
        root = etree.fromstring(content)
        if name == "word/document.xml":
            expand_service_rows(root, values)
            remove_empty_invoice_summary_rows(root, values)
        replace_placeholders(root, values)
        for instruction in root.xpath(".//w:instrText", namespaces=NS):
            instruction.text = PLACEHOLDER.sub(lambda match: values.get(match.group(1).split("|", 1)[0], ""), instruction.text or "")
        entries[name] = etree.tostring(root, xml_declaration=True, encoding="UTF-8", standalone=True)
    qr_embedded = False
    placeholder_hash = hashlib.sha256(placeholder_qr.read_bytes()).hexdigest()
    for name, content in list(entries.items()):
        if name.startswith("word/media/") and hashlib.sha256(content).hexdigest() == placeholder_hash:
            entries[name] = qr_path.read_bytes()
            qr_embedded = True
    target.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(target, "w", zipfile.ZIP_DEFLATED) as archive:
        for name, content in entries.items():
            archive.writestr(name, content)
    return placeholders, qr_embedded


def render(python: Path, renderer: Path, source: Path, output: Path, emit_pdf: bool = False) -> None:
    output.mkdir(parents=True, exist_ok=True)
    command = [str(python), str(renderer), str(source), "--output_dir", str(output), "--dpi", "150"]
    if emit_pdf:
        command.append("--emit_pdf")
    environment = dict(os.environ)
    libreoffice = Path(r"C:\Program Files\LibreOffice\program")
    if libreoffice.exists():
        environment["PATH"] = str(libreoffice) + os.pathsep + environment.get("PATH", "")
    result = subprocess.run(command, check=False, capture_output=True, text=True, env=environment)
    if result.returncode != 0:
        raise RuntimeError((result.stderr or result.stdout or "Renderer ohne Fehlertext beendet").strip())


def compare_pages(docx_render: Path, pdf_render: Path) -> tuple[int, float]:
    docx_pages = sorted(docx_render.glob("page-*.png"))
    pdf_pages = sorted(pdf_render.glob("page-*.png"))
    if not docx_pages or len(docx_pages) != len(pdf_pages):
        raise RuntimeError("DOCX-/PDF-Seitenzahl stimmt nicht überein")
    differences = []
    for left_path, right_path in zip(docx_pages, pdf_pages):
        with Image.open(left_path).convert("RGB") as left, Image.open(right_path).convert("RGB") as right:
            if left.size != right.size:
                raise RuntimeError("DOCX-/PDF-Seitengröße stimmt nicht überein")
            if ImageStat.Stat(left).mean[0] > 254.9:
                raise RuntimeError(f"Leere Seite erkannt: {left_path.name}")
            difference = ImageChops.difference(left, right)
            differences.append(sum(ImageStat.Stat(difference).mean) / 3)
    return len(docx_pages), max(differences, default=0.0)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("root", nargs="?", default=".")
    parser.add_argument("--output", default="output/template-qa-0.43.0")
    parser.add_argument("--renderer", required=True)
    parser.add_argument("--qr", required=True)
    parser.add_argument("--template", default="")
    parser.add_argument("--without-prior-invoice", action="store_true")
    parser.add_argument("--quote", action="store_true")
    arguments = parser.parse_args()
    root = Path(arguments.root).resolve()
    output = (root / arguments.output).resolve()
    if output.exists():
        shutil.rmtree(output)
    (output / "docx").mkdir(parents=True)
    (output / "pdf").mkdir(parents=True)
    values, catalog = build_values(root)
    if arguments.quote:
        values.update({
            "order.document_title": "Kostenvoranschlag",
            "order.confirmation_text": "Dieser Kostenvoranschlag stellt die ausgewählten Bestattungsleistungen sowie die zum Erstellungsdatum voraussichtlich anfallenden Kosten dar.",
            "order.status": "Entwurf", "order.workflow_detail": "22.09.2026",
            "order.services_heading": "Voraussichtliche Leistungen und Kosten",
            "order.cost_note": "Die Preise unserer eigenen Leistungen sind bis zum ausgewiesenen Gültigkeitsdatum verbindlich, sofern Art und Umfang der aufgeführten Leistungen unverändert bleiben. Beträge für Fremdleistungen und Fremdkosten sind Schätzbeträge, soweit sie nicht ausdrücklich als Festpreis bezeichnet sind; Preisänderungen Dritter können den Endbetrag verändern. Durchlaufende Posten werden in der tatsächlich verauslagten Höhe weitergegeben. Sobald eine wesentliche Kostenabweichung erkennbar wird, informieren wir den Auftraggeber unverzüglich.",
            "order.tax_heading": "Voraussichtliche Umsatzsteuerübersicht",
            "order.taxable_net_label": "Voraussichtliches steuerpflichtiges Entgelt netto",
            "order.total_vat_label": "Voraussichtliche Mehrwertsteuer",
            "order.pass_through_label": "Voraussichtliche durchlaufende Posten",
            "order.total_gross_label": "Voraussichtlicher Gesamtbetrag",
        })
    if arguments.without_prior_invoice:
        values["case.invoice_prior_gross"] = "0,00 EUR"
        values["case.invoice_sequence"] = "1"
    reports, all_placeholders, errors = [], set(), []
    placeholder_qr = root / "resources/payment-qr-placeholder.png"
    docx_sources = sorted((root / "resources/templates").glob("*.docx"))
    if arguments.template:
        docx_sources = [source for source in docx_sources if source.name.upper() == arguments.template.upper()]
    for source in docx_sources:
        target = output / "docx" / f"{source.stem}-QA.docx"
        try:
            placeholders, qr_embedded = materialize(source, target, values, Path(arguments.qr), placeholder_qr)
            all_placeholders.update(placeholders)
            errors.extend(f"{source.name}: {message}" for message in validate_catalog(placeholders, catalog))
            remaining = source_placeholders({name: data for name, data in zipfile_entries(target).items()})
            if remaining:
                errors.append(f"{source.name}: nicht ersetzte Platzhalter {sorted(remaining)}")
            docx_render = output / "rendered" / source.stem / "docx"
            render(Path(sys.executable), Path(arguments.renderer), target, docx_render, True)
            rendered_pdf = docx_render / f"{target.stem}.pdf"
            final_pdf = output / "pdf" / f"{source.stem}-QA.pdf"
            shutil.copy2(rendered_pdf, final_pdf)
            pdf_render = output / "rendered" / source.stem / "pdf"
            render(Path(sys.executable), Path(arguments.renderer), final_pdf, pdf_render)
            pages, visual_difference = compare_pages(docx_render, pdf_render)
            pdf_text = "\n".join((page.extract_text() or "") for page in PdfReader(final_pdf).pages)
            if "{{" in pdf_text or "}}" in pdf_text:
                errors.append(f"{source.name}: sichtbarer Platzhalter im PDF")
            iso_dates = sorted(set(re.findall(r"(?<!\d)20\d{2}-\d{2}-\d{2}(?!\d)", pdf_text)))
            if iso_dates:
                errors.append(f"{source.name}: ISO-Datum statt deutschem Datumsformat: {iso_dates}")
            reports.append({"template": source.name, "placeholders": len(placeholders), "pages": pages, "pdfBytes": final_pdf.stat().st_size, "visualDifference": round(visual_difference, 6), "qrEmbedded": qr_embedded, "status": "OK"})
        except Exception as error:
            errors.append(f"{source.name}: {error}")
            reports.append({"template": source.name, "placeholders": 0, "pages": 0, "pdfBytes": 0, "visualDifference": None, "qrEmbedded": False, "status": "ERROR"})
    text_sources = [] if arguments.template else sorted((root / "resources/templates").glob("*.txt"))
    for source in text_sources:
        content = source.read_text(encoding="utf-8")
        placeholders = {match.group(1).split("|", 1)[0] for match in PLACEHOLDER.finditer(content)}
        all_placeholders.update(placeholders)
        errors.extend(f"{source.name}: {message}" for message in validate_catalog(placeholders, catalog))
        rendered = PLACEHOLDER.sub(lambda match: values.get(match.group(1).split("|", 1)[0], ""), content)
        (output / source.name.replace(".txt", "-QA.txt")).write_text(rendered, encoding="utf-8")
        reports.append({"template": source.name, "placeholders": len(placeholders), "pages": 0, "pdfBytes": 0, "visualDifference": None, "qrEmbedded": False, "status": "OK"})
    invoice_was_selected = not arguments.template or arguments.template.upper() == "RECHNUNG.DOCX"
    if invoice_was_selected and not any(row["template"] == "RECHNUNG.docx" and row["qrEmbedded"] for row in reports):
        errors.append("RECHNUNG.docx: Zahlungs-QR wurde nicht eingebettet")
    result = {"release": "0.43.0", "sampleCase": "2026-0042 Helene Müller", "templates": reports, "catalogFields": len(catalog), "usedPlaceholders": len(all_placeholders), "errors": errors}
    (output / "template-qa-report.json").write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
    with (output / "template-qa-report.csv").open("w", encoding="utf-8-sig", newline="") as stream:
        writer = csv.DictWriter(stream, fieldnames=list(reports[0])); writer.writeheader(); writer.writerows(reports)
    print(json.dumps({"templates": len(reports), "docx": len(list((output / 'docx').glob('*.docx'))), "pdf": len(list((output / 'pdf').glob('*.pdf'))), "errors": errors}, ensure_ascii=False, indent=2))
    return 1 if errors else 0


def zipfile_entries(path: Path) -> dict[str, bytes]:
    with zipfile.ZipFile(path) as archive:
        return {name: archive.read(name) for name in archive.namelist()}


if __name__ == "__main__":
    raise SystemExit(main())
