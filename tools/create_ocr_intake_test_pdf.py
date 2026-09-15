from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle, getSampleStyleSheet
from reportlab.lib.units import mm
from reportlab.platypus import Paragraph, SimpleDocTemplate, Spacer, Table, TableStyle


OUTPUT = Path(__file__).resolve().parents[1] / "output" / "pdf" / "auftragserfassungsbogen-testdaten.pdf"


def main() -> None:
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    styles = getSampleStyleSheet()
    title = ParagraphStyle(
        "FormTitle",
        parent=styles["Title"],
        alignment=TA_CENTER,
        fontName="Helvetica-Bold",
        fontSize=18,
        leading=22,
        textColor=colors.HexColor("#00679e"),
        spaceAfter=5 * mm,
    )
    note = ParagraphStyle(
        "Note",
        parent=styles["Normal"],
        alignment=TA_CENTER,
        fontSize=9,
        leading=12,
        textColor=colors.HexColor("#555555"),
    )
    section = ParagraphStyle(
        "Section",
        parent=styles["Heading2"],
        fontName="Helvetica-Bold",
        fontSize=11,
        leading=14,
        textColor=colors.HexColor("#00679e"),
        spaceBefore=4 * mm,
        spaceAfter=2 * mm,
    )

    doc = SimpleDocTemplate(
        str(OUTPUT),
        pagesize=A4,
        rightMargin=16 * mm,
        leftMargin=16 * mm,
        topMargin=14 * mm,
        bottomMargin=14 * mm,
        title="Auftragserfassungsbogen – Testdaten",
        author="Bestatter-App Test",
    )
    story = [
        Paragraph("Auftragserfassungsbogen – Testdaten", title),
        Paragraph("Nur für den OCR- und Schnellerfassungstest · Formular AUFTRAGSERFASSUNG_STANDARD / Version 1", note),
        Spacer(1, 4 * mm),
    ]

    sections = [
        ("Verstorbene Person", [
            ("Vorname", "Erika"),
            ("Nachname", "Beispiel"),
            ("Geburtsname", "Mustermann"),
            ("Geburtsdatum", "14.02.1948"),
            ("Geburtsort", "Bremen"),
            ("Sterbedatum", "05.09.2026"),
            ("Sterbeort", "Klinikum Bremen-Mitte"),
            ("Geburtsstandesamt", "Bremen-Mitte"),
            ("Beruf", "Buchhalterin"),
            ("Postrentennummer", "12 140248 B 501"),
            ("Familienstand", "verwitwet"),
            ("Religion", "evangelisch"),
        ]),
        ("Letzter Wohnsitz", [
            ("Straße und Hausnummer", "Musterweg 17"),
            ("PLZ", "28195"),
            ("Wohnort", "Bremen"),
        ]),
        ("Bestattungswunsch", [
            ("Bestattungsart", "Feuerbestattung"),
            ("Friedhof", "Riensberger Friedhof"),
        ]),
        ("Auftraggeberin", [
            ("Vorname Auftraggeberin", "Anna"),
            ("Nachname Auftraggeberin", "Beispiel"),
            ("Beziehung Auftraggeberin", "Tochter"),
            ("Mobil Auftraggeberin", "0170 0000000"),
        ]),
        ("Urkunden", [
            ("Sterbeurkunden gebührenfrei", "2"),
            ("Sterbeurkunden gebührenpflichtig", "3"),
        ]),
    ]

    for heading, rows in sections:
        story.append(Paragraph(heading, section))
        data = [[Paragraph(f"<b>{label}</b>", styles["BodyText"]), Paragraph(value, styles["BodyText"])] for label, value in rows]
        table = Table(data, colWidths=[62 * mm, 100 * mm], hAlign="LEFT")
        table.setStyle(TableStyle([
            ("BACKGROUND", (0, 0), (0, -1), colors.HexColor("#f3f6f8")),
            ("GRID", (0, 0), (-1, -1), 0.35, colors.HexColor("#c8d1d8")),
            ("VALIGN", (0, 0), (-1, -1), "MIDDLE"),
            ("LEFTPADDING", (0, 0), (-1, -1), 6),
            ("RIGHTPADDING", (0, 0), (-1, -1), 6),
            ("TOPPADDING", (0, 0), (-1, -1), 4),
            ("BOTTOMPADDING", (0, 0), (-1, -1), 4),
        ]))
        story.append(table)

    story.extend([
        Spacer(1, 5 * mm),
        Paragraph("Testlauf-ID: OCR-TEST-2026-09-07-A", note),
    ])
    doc.build(story)
    print(OUTPUT)


if __name__ == "__main__":
    main()
