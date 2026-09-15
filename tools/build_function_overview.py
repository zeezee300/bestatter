from docx import Document
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH, WD_BREAK
from docx.enum.section import WD_SECTION
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_CELL_VERTICAL_ALIGNMENT
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.enum.style import WD_STYLE_TYPE
from docx.enum.text import WD_LINE_SPACING
from pathlib import Path

OUT = Path('output/Bestatter_Funktionsuebersicht_aktuell_DU.docx')
OUT.parent.mkdir(parents=True, exist_ok=True)

NAVY = '17324D'
BLUE = '2B6CB0'
TEAL = '0F766E'
GOLD = 'B7791F'
LIGHT = 'EAF1F7'
PALE = 'F6F8FA'
GRAY = 'D9E0E7'
TEXT = '263238'

def shade(cell, fill):
    tcPr = cell._tc.get_or_add_tcPr()
    shd = tcPr.find(qn('w:shd'))
    if shd is None:
        shd = OxmlElement('w:shd'); tcPr.append(shd)
    shd.set(qn('w:fill'), fill)

def cell_margins(cell, top=100, start=130, bottom=100, end=130):
    tc = cell._tc; tcPr = tc.get_or_add_tcPr()
    tcMar = tcPr.first_child_found_in('w:tcMar')
    if tcMar is None:
        tcMar = OxmlElement('w:tcMar'); tcPr.append(tcMar)
    for m, v in [('top', top), ('start', start), ('bottom', bottom), ('end', end)]:
        node = tcMar.find(qn('w:'+m))
        if node is None: node = OxmlElement('w:'+m); tcMar.append(node)
        node.set(qn('w:w'), str(v)); node.set(qn('w:type'), 'dxa')

def set_cell_text(cell, text, bold=False, color=TEXT, size=9.5):
    cell.text = ''
    p = cell.paragraphs[0]; p.paragraph_format.space_after = Pt(0)
    r = p.add_run(text); r.bold = bold; r.font.name = 'Aptos'; r.font.size = Pt(size); r.font.color.rgb = RGBColor.from_string(color)
    cell.vertical_alignment = WD_CELL_VERTICAL_ALIGNMENT.CENTER
    cell_margins(cell)

def borders(table, color=GRAY, sz='6'):
    tbl = table._tbl; tblPr = tbl.tblPr
    b = tblPr.first_child_found_in('w:tblBorders')
    if b is None: b = OxmlElement('w:tblBorders'); tblPr.append(b)
    for edge in ('top','left','bottom','right','insideH','insideV'):
        e = b.find(qn('w:'+edge))
        if e is None: e = OxmlElement('w:'+edge); b.append(e)
        e.set(qn('w:val'),'single'); e.set(qn('w:sz'),sz); e.set(qn('w:space'),'0'); e.set(qn('w:color'),color)

def add_table(doc, headers, rows, widths=None):
    t = doc.add_table(rows=1, cols=len(headers)); t.alignment = WD_TABLE_ALIGNMENT.CENTER; t.autofit = False
    for i,h in enumerate(headers):
        set_cell_text(t.rows[0].cells[i], h, True, 'FFFFFF', 9)
        shade(t.rows[0].cells[i], NAVY)
        if widths: t.rows[0].cells[i].width = Inches(widths[i])
    for ri,row in enumerate(rows):
        cells = t.add_row().cells
        trPr = t.rows[-1]._tr.get_or_add_trPr()
        cant = OxmlElement('w:cantSplit'); trPr.append(cant)
        for i,val in enumerate(row):
            set_cell_text(cells[i], str(val), False, TEXT, 9)
            if widths: cells[i].width = Inches(widths[i])
            if ri % 2 == 1: shade(cells[i], PALE)
    borders(t); doc.add_paragraph().paragraph_format.space_after = Pt(2)
    hdrPr = t.rows[0]._tr.get_or_add_trPr()
    repeat = OxmlElement('w:tblHeader'); repeat.set(qn('w:val'), 'true'); hdrPr.append(repeat)
    return t

def add_bullet(doc, text, style='List Bullet'):
    p = doc.add_paragraph(style=style); p.paragraph_format.space_after = Pt(3); p.paragraph_format.left_indent = Inches(0.22)
    p.add_run(text); return p

def add_label(doc, text, color=TEAL):
    p=doc.add_paragraph(); p.paragraph_format.space_before=Pt(6); p.paragraph_format.space_after=Pt(2)
    r=p.add_run(text.upper()); r.bold=True; r.font.name='Aptos'; r.font.size=Pt(8.5); r.font.color.rgb=RGBColor.from_string(color); r.font.letter_spacing if False else None
    return p

def add_heading(doc, text, level=1):
    p=doc.add_paragraph(style=f'Heading {level}'); p.add_run(text); return p

doc=Document()
sec=doc.sections[0]; sec.top_margin=Inches(0.65); sec.bottom_margin=Inches(0.62); sec.left_margin=Inches(0.72); sec.right_margin=Inches(0.72)

styles=doc.styles
styles['Normal'].font.name='Aptos'; styles['Normal'].font.size=Pt(10); styles['Normal'].font.color.rgb=RGBColor.from_string(TEXT)
styles['Normal'].paragraph_format.space_after=Pt(6); styles['Normal'].paragraph_format.line_spacing=1.08
for n,size,col in [('Title',30,NAVY),('Heading 1',19,NAVY),('Heading 2',13,BLUE),('Heading 3',11,TEAL)]:
    s=styles[n]; s.font.name='Aptos Display' if n in ('Title','Heading 1') else 'Aptos'; s.font.size=Pt(size); s.font.bold=True; s.font.color.rgb=RGBColor.from_string(col); s.paragraph_format.space_before=Pt(12); s.paragraph_format.space_after=Pt(6)
styles['Title'].paragraph_format.space_before=Pt(36); styles['Title'].paragraph_format.space_after=Pt(8)

# cover
p=doc.add_paragraph(style='Title'); p.add_run('Bestatter')
p=doc.add_paragraph(); p.paragraph_format.space_after=Pt(5); r=p.add_run('Funktionsübersicht aktuell'); r.bold=True; r.font.size=Pt(20); r.font.color.rgb=RGBColor.from_string(BLUE)
p=doc.add_paragraph(); p.paragraph_format.space_after=Pt(24); r=p.add_run('Entscheidungsvorlage für Deinen nächsten Ausbauschritt'); r.font.size=Pt(13); r.font.color.rgb=RGBColor.from_string(TEAL)
add_table(doc,['Aktueller Stand','Was Du damit gewinnst','Einordnung'],[
    ['Version 0.45.0','Fallzentriertes Arbeiten vom Erstkontakt bis zur Abrechnung','Produktiver Funktionsstand'],
    ['Betriebsmodell','Nextcloud-App mit rollenbasierter Berechtigung, Dateiablage und Groupware-Anbindung','Selbst gehostet'],
    ['Leitprinzip','Nachvollziehbarkeit von Leistung, Kosten, Dokumenten und Freigaben','Prüfbar statt Blackbox'],
], [1.25,3.25,2.05])
p=doc.add_paragraph(); p.paragraph_format.space_before=Pt(18); r=p.add_run('Kurz gesagt'); r.bold=True; r.font.size=Pt(12); r.font.color.rgb=RGBColor.from_string(NAVY)
doc.add_paragraph('Du erhältst eine digitale Fallakte, die operative Arbeit, Dokumente, Termine, Leistungen und Rechnungen in einem durchgängigen Prozess verbindet. Der entscheidende Mehrwert liegt in der kontrollierten Abrechnung: Erbrachte Leistungen und Kosten bleiben bis zur Rechnung nachvollziehbar, kritische Abweichungen werden vor der Freigabe sichtbar.')
p=doc.add_paragraph(); p.paragraph_format.space_before=Pt(18); r=p.add_run('Stand: 5. September 2026  |  Grundlage: Projektstand 0.45.0'); r.italic=True; r.font.size=Pt(9); r.font.color.rgb=RGBColor.from_string('667781')
# executive summary
doc.add_page_break()
add_heading(doc,'1 Entscheidung auf einen Blick',1)
doc.add_paragraph('Wenn Du die Bestatter-App bewertest, stehen drei Fragen im Mittelpunkt: Unterstützt sie den Alltag, schützt sie die Abrechnung und lässt sie sich kontrolliert weiterentwickeln? Der aktuelle Stand beantwortet alle drei Fragen mit einem belastbaren Ja – mit klar benannten Grenzen beim Zahlungswesen, bei vollständigen Dienstplänen und bei optionalen KI-/Paperless-Ausbaustufen.')
add_table(doc,['Entscheiderfrage','Antwort heute','Nutzen'],[
    ['Wie viel Alltag ist abgedeckt?','Fallanlage, Aufgaben, Termine, Dokumente, Leistungen, Abmeldungen und Rechnungen sind verbunden.','Weniger Medienbrüche'],
    ['Wie sicher ist die Abrechnung?','Deterministische Prüfungen erkennen fehlende, doppelte, überhöhte oder widersprüchliche Positionen.','Weniger Umsatz- und Prüfungsrisiken'],
    ['Wie steuerbar ist der Betrieb?','Kennzahlen, CSV-Exporte, Audit, Systemprüfung und Benachrichtigungen sind integriert.','Bessere Führung mit belastbaren Signalen'],
    ['Was bleibt offen?','Zahlungseingänge, Mahnwesen, vollständige Kapazitätsplanung und optionale Integrationen sind bewusst abgegrenzt.','Realistische Roadmap'],
], [1.55,3.4,1.6])
add_heading(doc,'Der Kernnutzen für Dein Unternehmen',2)
for x in ['Du arbeitest fallzentriert statt in voneinander getrennten Listen.','Du siehst früh, wo Fristen, Dokumente oder Leistungen fehlen.','Du kannst Teil- und Schlussrechnungen aus tatsächlich erbrachten Mengen ableiten.','Du hältst Freigaben, Begründungen und Änderungen revisionsfähig fest.','Du steuerst Niederlassungen, Kennzahlen und Exporte mit einer gemeinsamen Datenbasis.']:
    add_bullet(doc,x)

add_heading(doc,'2 Der durchgängige Fallprozess',1)
doc.add_paragraph('Die Anwendung folgt dem Lebenszyklus eines Sterbefalls. Jeder Schritt baut auf den vorherigen Daten auf und erzeugt nachvollziehbare nächste Aktionen.')
add_table(doc,['Phase','Was Du erledigst','Kontrollpunkt'],[
    ['1 Beratung & Erfassung','Fall anlegen, Angehörige und Auftraggeber erfassen, Daten per Schnellerfassung prüfen.','Keine automatische Übernahme ohne Bestätigung'],
    ['2 Planung & Auftrag','Leistungen auswählen, KVA festschreiben, Auftrag einmalig übernehmen.','Unveränderlicher Auftragssnapshot'],
    ['3 Durchführung','Aufgaben, Termine, Mitarbeitende und Ressourcen koordinieren.','Konflikte und Änderungen werden begründet'],
    ['4 Dokumente & Behörden','Vorlagen ausgeben, Abmeldungen vorbereiten, Nachweise im Fallordner ablegen.','Status und Anlagen bleiben sichtbar'],
    ['5 Leistung & Kosten','Erbrachte Mengen, Eingangsrechnungen, Fremdkosten und Auslagen zuordnen.','Abgleich gegen Fallleistung'],
    ['6 Abrechnung & Abschluss','Sicherheitsprüfung, Teil-/Schlussrechnung, Freigabe, Versandnachweis und Audit.','Kritische Abweichungen blockieren'],
], [1.35,3.55,1.65])
# modules
add_heading(doc,'3 Funktionsumfang für Deinen Alltag',1)
doc.add_paragraph('Die folgende Übersicht beschreibt den heute nutzbaren Umfang. Die Formulierungen sind bewusst aus Deiner Perspektive gehalten: Was Du tun kannst und was dabei abgesichert wird.')
modules=[
('Fallakte und Stammdaten','Du führst Sterbedaten, Todeszeitpunkt, Wohnsitz, Standesämter, Friedhof, Bestattungsart, Auftraggeber, Betreuung sowie Ehe- und Partnerschaftsdaten strukturiert in einer zentralen Fallakte.','Fallübersicht, Vollständigkeitsprüfung, globale Suche, mobile Fallkarten'),
('Schnellerfassung und Bestatter-Assistent','Du kannst Gesprächstext oder Spracheingaben strukturiert erfassen. Erkannte Angaben und Aufgaben werden vor der Übernahme angezeigt und müssen ausdrücklich bestätigt werden.','Regelbasierter Betrieb ohne LLM; sensible Zwischenstände bleiben kontrolliert'),
('Aufgaben, Checklisten und Workflows','Du nutzt Checklisten, Fristen, Prioritäten und kontrollierte Folgeaktionen. Wiedervorlagen, Kontaktaktivitäten, Dokumentaktionen und Statusänderungen lassen sich als Workflow verbinden.','Weniger vergessene Folgeschritte'),
('Termine und Ressourcen','Du planst Trauerfeiern und andere Termine mit Terminarten, Vor-/Nachlauf und benötigten Mitarbeitenden, Fahrzeugen, Räumen, Kapellen oder Ausstattung.','Serverseitige Konfliktprüfung und begründete Übersteuerung'),
('Leistungskatalog und Auftrag','Du pflegst Artikel, Pakete, Mengeneinheiten, Preise und exklusive Artikelgruppen. KVA und Auftrag bleiben versionssicher; ein festgeschriebener KVA wird nur einmal übernommen.','Saubere Grundlage für Leistung und Rechnung'),
('Leistungserfassung und Eingangsrechnungen','Du dokumentierst erbrachte Mengen, Fremdkosten, Auslagen und Lieferantenbelege. Eingangsrechnungen führen Dich durch Beleg, Daten, Positionsabgleich, Prüfung und Abschluss.','Durchgängiger Kostenbezug'),
('Dokumente und Abmeldungen','Du erzeugst DOCX/PDF-Ausgaben aus konfigurierten Vorlagen, siehst Status und Versionen und bereitest Abmeldungen mit Anlagen und Versandnachweis vor.','Kein automatischer Versand; externes Handeln bleibt bewusst bei Dir'),
('Rechnungen und E-Rechnung','Du erzeugst Teil- und Schlussrechnungen mit Positions-/Teilmengenauswahl, Rechnungsfolge, Zahlungsziel, Bankdaten, EPC-SEPA-QR und strukturierter XML-Ausgabe.','Freigabe erst nach nachvollziehbarem Abschlussnachweis'),
('Benachrichtigungen und Audit','Du erhältst – je nach Einstellung – E-Mail-/Push-Hinweise zu Fallzuweisungen, Aufgaben, Terminen, Dokumenten, Rechnungen und fehlgeschlagenen Workflows.','Audit und Fall-Verlauf bleiben unabhängig davon vollständig'),
('Kennzahlen und Auswertungen','Du analysierst offene Fälle, Aufgaben, Planbelastung, dokumentierte Istzeit, Leistungserbringung, Fakturierungsgrad, Rechnungsvolumen und Niederlassungen.','Plausibilitätsprüfungen und datensparsame CSV-Exporte'),
]
for title,body,proof in modules:
    add_heading(doc,title,2); doc.add_paragraph(body); p=doc.add_paragraph(); p.paragraph_format.left_indent=Inches(.2); r=p.add_run('Sichtbar in der Anwendung: '); r.bold=True; r.font.color.rgb=RGBColor.from_string(TEAL); p.add_run(proof)

add_heading(doc,'4 Was die Abrechnung besonders macht',1)
doc.add_paragraph('Die Abrechnungssicherheits-Engine ist der fachliche Differenzierer. Sie arbeitet regelbasiert und reproduzierbar. KI kann später Auffälligkeiten vorschlagen, ist aber keine Voraussetzung für eine korrekte Pflichtprüfung.')
add_table(doc,['Prüfung','Beispiel','Ergebnis'],[
    ['Leistung ohne Rechnung','Eine erbrachte Leistung hat keine Rechnungsposition.','Kritisch'],
    ['Mengenabweichung','Die berechnete Menge ist kleiner als die erbrachte Menge.','Warnung oder kritisch'],
    ['Doppelabrechnung','Eine Leistung wird in mehreren Rechnungen erneut berücksichtigt.','Kritisch'],
    ['Fremdkosten ohne Bezug','Ein Lieferantenbeleg ist keiner abrechenbaren Position zugeordnet.','Kritisch'],
    ['Kulanz','Eine nicht berechnete Position ist ausdrücklich als Kulanz markiert.','OK'],
    ['Fehlende Freigabe','Ein kritischer Ausnahmefall wurde noch nicht begründet freigegeben.','Blockiert'],
], [1.55,3.7,1.3])
add_heading(doc,'5 Führungsinformationen statt bloßer Zähler',1)
doc.add_paragraph('Die Auswertungen helfen Dir bei der Steuerung, ohne mehr Präzision vorzutäuschen, als die Daten hergeben. Planbelastung wird von dokumentierter Istzeit getrennt; eine echte Mitarbeiterauslastung wird erst berechnet, wenn Dienstpläne, Arbeitszeitmodelle und Abwesenheiten verfügbar sind.')
add_table(doc,['Bereich','Du siehst','Wofür es Dir hilft'],[
    ['Operativ','Offene, erledigte und überfällige Aufgaben sowie Termine.','Tagessteuerung'],
    ['Leistung','Beauftragte, erbrachte und fakturierte Mengen.','Nachverfolgung von Umsatzpotenzial'],
    ['Finanzen','Rechnungsvolumen, freigegebenes Volumen und Durchschnittswerte.','Kaufmännischer Überblick bis zur Ausgabe'],
    ['Qualität','Plausibilitätsprüfungen zu Zuständigkeiten, Zeitabdeckung, Mengen und Rechnungsarithmetik.','Frühe Korrektur statt spätem Suchen'],
    ['Datenexport','Fälle, Leistungsstatus, Rechnungen, Niederlassungen und Aktivitäten als CSV.','Übergabe an interne Steuerung oder Buchhaltung'],
], [1.25,3.2,2.1])

add_heading(doc,'6 Integrationen und Betrieb',1)
doc.add_paragraph('Bestatter nutzt Nextcloud als Datei- und Kollaborationsplattform. Die App bleibt fachlich führend für Fälle, Leistungen, Kosten, Rechnungen und Prüfungen; Dateien werden über die vorgesehenen Nextcloud-Schnittstellen abgelegt und verlinkt.')
add_table(doc,['Baustein','Heute','Einordnung'],[
    ['Nextcloud','Fallordner, Dateien, Kalender, Kontakte, Tasks, Aktivitäten und Benachrichtigungen.','Produktiv genutzt'],
    ['CalDAV','Persönliche Kalenderkopien, Terminänderungen und Absagen werden synchronisiert.','Mit fachlicher Historie'],
    ['Paperless-ngx','Als optionale Dokument-/OCR-/Archivstufe konzeptionell vorgesehen.','Nicht Voraussetzung für den Kernprozess'],
    ['Keycloak / OIDC','Im Zielkonzept für zentrale Anmeldung und MFA vorgesehen.','Architekturentscheidung'],
    ['Redis / Hintergrundjobs','Systemprüfung und wiederholbare Hintergrundprozesse innerhalb der Nextcloud-Umgebung.','Betrieblich abgesichert'],
], [1.45,3.55,1.55])
add_heading(doc,'7 Bewusste Grenzen und nächste Ausbauschritte',1)
doc.add_paragraph('Ein belastbares Produkt braucht sichtbare Grenzen. Diese Punkte sind nicht versteckte Lücken, sondern bewusst getrennte Ausbaustufen:')
add_table(doc,['Thema','Heute','Nächster sinnvoller Schritt'],[
    ['Zahlungswesen','Rechnung bis Versandnachweis, Teil-/Vollzahlung und begründete Stornierung.','Offene Posten, Mahnwesen und Gutschriften'],
    ['Personaldisposition','Planbelastung und Terminressourcen; Konfliktprüfung vorhanden.','Dienstpläne, Arbeitszeitmodelle, Abwesenheiten und echte Auslastung'],
    ['KI / Sprache','Geführte, bestätigungspflichtige Erfassung und regelbasierter Assistent.','Optionale lokale oder freigegebene Provider'],
    ['Paperless-Integration','Kernprozess funktioniert ohne Paperless.','OCR-/Klassifikationsadapter mit Wiederholung'],
    ['Mobile Nutzung','Responsive Oberfläche / PWA-Ansatz.','Native App erst nach belastbarem End-to-End-Betrieb'],
], [1.35,3.25,1.95])

add_heading(doc,'8 Fazit für die Entscheidung',1)
doc.add_paragraph('Die Bestatter-App ist heute vor allem dann interessant, wenn Du einen kontrollierten, fallzentrierten Kern für Dein Unternehmen suchst: mit nachvollziehbaren Leistungen, sauberer Dokumentablage, integrierter Termin- und Aufgabensteuerung sowie einer Abrechnung, die kritische Abweichungen vor der Freigabe sichtbar macht.')
doc.add_paragraph('Für die nächste Entscheidung empfehle ich, den aktuellen Stand an einem realistischen Musterfall zu bewerten: vom Erstgespräch über Auftrag und Trauerfeier bis zu Eingangsrechnung, Abrechnungssicherheitsprüfung, Rechnung und dokumentiertem Versandnachweis. Genau dort wird der Nutzen im Tagesgeschäft am schnellsten sichtbar.')

# footer
for section in doc.sections:
    footer=section.footer; p=footer.paragraphs[0]; p.alignment=WD_ALIGN_PARAGRAPH.RIGHT
    p.add_run('Bestatter | Funktionsübersicht aktuell  ·  ')
    fld=OxmlElement('w:fldSimple'); fld.set(qn('w:instr'),'PAGE'); p._p.append(fld)
    for run in p.runs: run.font.name='Aptos'; run.font.size=Pt(8); run.font.color.rgb=RGBColor.from_string('72808A')

doc.core_properties.title='Bestatter Funktionsübersicht aktuell'
doc.core_properties.subject='Aktueller Funktionsumfang und Entscheidungsvorlage'
doc.core_properties.author='Bestatter'
doc.save(OUT)
print(OUT.resolve())
