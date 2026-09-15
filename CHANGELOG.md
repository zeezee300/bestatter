# Änderungsstand

## 0.59.0 – Nebenaufträge in Fallführung und Abschlussprüfung integriert

- Nebenaufträge werden kompakt im eigenen Fallreiter geführt; Anlageformulare bleiben standardmäßig geschlossen und genau ein ausgewählter Auftrag zeigt Kopfdaten, Positionen oder Abrechnung.
- Positionen und Finanzen behalten den Nebenauftragskontext und zeigen Nummer sowie Auftraggeber; der allgemeine Leistungsreiter bleibt dem Hauptauftrag vorbehalten.
- Fallliste, Suche und Filter berücksichtigen Nebenauftragsnummer, Auftraggeber, Status und offene Abrechnung und stellen Nebenaufträge eingerückt unter dem Sterbefall dar.
- Der Fallabschluss ist serverseitig gesperrt, solange nicht stornierte Nebenaufträge offen sind. Abgeschlossene oder stornierte Fälle nehmen keine neuen Nebenaufträge an.
- Das Betriebs-Cockpit meldet widersprüchliche Bestandsfälle rein lesend; neue Suchindizes unterstützen die erweiterten Fallabfragen.

## 0.58.2 – Nebenauftragsverwaltung vollständig bedienbar

- Der Bereich „Weitere Auftraggeber / Nebenaufträge“ ist im gebauten Produktions-Frontend sichtbar.
- Nebenaufträge können angelegt, im Entwurf bearbeitet, beauftragt, abgerechnet, abgeschlossen und kontrolliert storniert werden.
- Positionen, Rechnungsprüfung und Rechnungen bleiben strikt vom Hauptauftrag sowie von anderen Nebenaufträgen getrennt.
- Beauftragung prüft vollständige Auftraggeberdaten und mindestens eine Position; der Abschluss verlangt eine freigegebene Schlussrechnung.
- Das Release-Paket enthält ausschließlich erforderliche Laufzeitdateien.

## 0.58.1 – BEST-114 Nebenaufträge abrechnen

- Rechnungen, Leistungsprüfungen und Rechnungspositionen können eindeutig einem Nebenauftrag zugeordnet werden.
- Nebenaufträge erhalten eigene Rechnungsempfänger, unabhängige Schlussrechnungs-Sperren und fortlaufende Rechnungssequenzen innerhalb des Falls.
- Auswertungen weisen Nebenauftragsnummern getrennt aus, bleiben aber über die Fallnummer verknüpft.

## 0.58.0 – BEST-113 Basiserfassung Nebenaufträge

- Nebenaufträge können einem bestehenden Sterbefall mit eigener Nummer, eigenem Auftraggeber, Beziehung, Status und optionaler Lieferfrist zugeordnet werden.
- Nebenauftragspositionen werden über eine nullable `side_order_id` getrennt vom Hauptauftrag gespeichert und über dieselbe Positionsmaske erfasst.
- Vertragsschutz und Änderungsberechtigungen werden je Auftragskreis ausgewertet; bestehende Hauptaufträge bleiben rückwärtskompatibel.
- Die Fallübersicht enthält einen Bereich „Nebenaufträge“ mit Erfassung, Stornierung und direktem Einstieg in die Positionsbearbeitung.
- Migration 3500 ist additiv und idempotent.

## 0.57.1 – Verlässliche Zahlungs-QR-Ausgabe

- Zahlungsart, effektiver QR-Standard und die für den Zahlcode verwendeten Bank-/Mandatsdaten werden bei der Rechnungsanlage als unveränderlicher Zahlungssnapshot gespeichert. Eine spätere Änderung am Auftrag oder Niederlassungsstamm verändert dadurch ein bereits angelegtes Rechnungsprüfdokument nicht mehr.
- Globaler Schalter und Niederlassungsprofil werden gemeinsam ausgewertet. Bei Überweisung und aktivem EPC-Standard wird eine Rechnung nicht mehr stillschweigend ohne erzeugbaren QR-Code ausgegeben.
- Die Oberfläche unterscheidet nun verständlich zwischen eingebettetem Zahlcode, bestimmungsgemäßer Unterdrückung bei SEPA-Lastschrift und administrativ deaktivierter QR-Funktion.
- Der QR-Bereich der Rechnung wird abhängig von der Zahlungsart beschriftet, sodass ein absichtlich leeres Bildfeld nicht mehr wie ein Renderfehler wirkt.
- Eine unveränderte Standard-Rechnungsvorlage aus 0.57.0 wird beim nächsten Dokumentlauf automatisch aktualisiert; individuell bearbeitete Vorlagen bleiben geschützt.
- Migration 3400 ergänzt den optionalen Zahlungssnapshot für bestehende Installationen. Altrechnungen ohne Snapshot verwenden aus Kompatibilitätsgründen weiterhin den aktuellen Datenstand.

## 0.57.0 – BEST-113 Dokumentvorschau und atomare Entwurfspakete

- „Drucken“ in der Auftrag-/KVA-Erfassung wurde durch einen Vorschau-first-Ablauf ersetzt; die Erfassungsmaske selbst wird nicht mehr an den Browserdruck übergeben.
- KVA, Bestattungsauftrag und Bestattungsvollmacht werden als echte, schreibgeschützte PDF-Vorschau angezeigt, ohne Dateien oder Dokumentdatensätze in der Fallakte anzulegen.
- Im Auftragsmodus können Auftrag und Vollmacht innerhalb eines gemeinsamen, bildschirmfüllenden Vorschaudialogs umgeschaltet und jeweils als PDF geöffnet beziehungsweise gedruckt werden.
- Entwurfspakete werden zunächst vollständig unter eindeutigen Staging-Namen erzeugt und anschließend gemeinsam in einer Datenbanktransaktion registriert.
- Bei einem Erzeugungs- oder Speicherfehler werden die neuen Paketdateien entfernt; der bisherige Entwurf bleibt erhalten. Erst nach erfolgreichem Commit werden ältere Entwurfsdateien bereinigt.
- Mehrfachklicks werden während Vorschau- und Paketerzeugung blockiert; vorhandene Entwürfe werden nur nach ausdrücklicher Bestätigung aktualisiert.

## 0.56.1 – Kostenvoranschlag mit transparenten Preisblöcken

- Kostenvoranschlag und Bestattungsauftrag verwenden nun dieselbe gegliederte Leistungsdarstellung wie die Rechnung; leere Blöcke für EL, FK und DP werden nicht ausgegeben.
- Eigene Leistungen werden im Kostenvoranschlag als verbindliche Angebotspreise ausgewiesen, Fremdleistungen und Fremdkosten dagegen als voraussichtliche Schätzbeträge.
- Gebühren und echte durchlaufende Posten erscheinen als eigener voraussichtlicher Block und werden im Hinweistext von Fremdleistungen abgegrenzt.
- Jeder ausgegebene Block erhält eine Brutto-Zwischensumme; der Summenbereich unterscheidet steuerpflichtiges Netto, Umsatzsteuer, durchlaufende Posten und Gesamtbetrag.
- Die Umsatzsteuerübersicht wird nach Steuersätzen aufgeteilt; DP-Positionen fließen nicht in das steuerpflichtige Entgelt ein.
- Der KVA enthält einen transparenten Hinweis zu Gültigkeit, Preisänderungen Dritter, tatsächlicher Weitergabe durchlaufender Posten und Informationspflicht bei wesentlichen Abweichungen.
- Eine byte-identische bisherige Standardvorlage wird beim nächsten Dokumentlauf sicher aktualisiert; jede individuell veränderte Vorlage bleibt erhalten.

## 0.56.0 – BEST-112: Nachträge nach Teilrechnung

- Eine Teilrechnung sperrt nicht mehr pauschal die gesamte Auftragspositionserfassung. Begründete Nachträge bleiben bis zur aktiven Schlussrechnung möglich.
- Die bestehende Pflichtbegründung und das Audit-Ereignis `CONTRACT_AMENDMENT` gelten unverändert auch nach Teilrechnungen.
- Neu ergänzte Vertragspositionen werden ohne Schemaänderung mit `origin = NACHTRAG` gespeichert und als `NTR` in Positionsübersicht und Leistungsbericht gekennzeichnet.
- Bereits fakturierte Positionen bleiben serverseitig in Menge, Preis und weiteren Vertragsdaten geschützt und werden in der Nachtragsoberfläche deaktiviert.
- Der Fakturierungsstand wird je Position angezeigt; nicht fakturierte Nachtragspositionen gelangen nach Leistungserfassung über die vorhandene Restmengenlogik in Folge- oder Schlussrechnungen.
- Eine aktive Schlussrechnung friert die Leistungsliste weiterhin vollständig ein.

## 0.55.1 – Stabiler und größerer Leistungskatalogdialog

- Der Ergebnisbereich behält nach Auswahl, Abwahl sowie Änderung von Menge oder Preis seine Scrollposition und springt nach dem automatischen Speichern nicht mehr an den Anfang.
- Filter-, Such- und Seitenwechsel setzen die Ergebnisposition weiterhin bewusst auf den Anfang der neuen Ergebnismenge.
- Der Katalogdialog nutzt auf Desktop-Bildschirmen bis zu 1.680 Pixel Breite und nahezu die gesamte verfügbare Fensterhöhe.
- Artikelliste und Auswahlzusammenfassung besitzen getrennte, stabile Scrollbereiche; auf kleineren Bildschirmen wechselt die Darstellung weiterhin in ein einspaltiges Layout.

## 0.55.0 – Plausibilitätsprüfung vor Festschreibung

- Der Wechsel vom Entwurf zum festgeschriebenen KVA beziehungsweise beauftragten Auftrag führt nun über eine zentrale Vorprüfung.
- Blockierende Prüfungen umfassen Fallbezug, vollständige Auftraggeber- und gegebenenfalls Rechnungsempfängerdaten, Dokumentdaten, fehlerfreie Positionen, Freitextpreise, zulässige MwSt.-Sätze und vollständige SEPA-Mandatsdaten.
- Fachliche Auffälligkeiten wie Nullpreise, 0 % MwSt., doppelte Artikel, FK/DP ohne Erläuterung, fehlende Kontaktdaten oder fehlende digitale Bestätigung müssen bewusst bestätigt werden.
- Statusauswahl und Festschreibungsbutton verwenden denselben Ablauf; direkte API-Aufrufe werden serverseitig mit denselben Regeln geschützt.
- Prüfergebnis, bestätigte Hinweis-Codes, Zeitpunkt und Benutzer werden im unveränderlichen Dokument-Snapshot gespeichert.

## 0.54.1 – Flexible Materialauswahl in langen Positionslisten

- Die Material-/Leistungsvorschläge werden als schwebende Ebene außerhalb des horizontalen Tabellen-Scrollcontainers dargestellt und dadurch auch an unteren Positionen nicht mehr abgeschnitten.
- Die Trefferliste öffnet sich abhängig vom verfügbaren Platz automatisch ober- oder unterhalb des Eingabefelds und passt Breite sowie Höhe an das Browserfenster an.
- Bei Größenänderungen und Scrollbewegungen bleibt die Liste am Materialfeld ausgerichtet; Maus- und Tastaturbedienung bleiben unverändert.

## 0.54.0 – Stabile und erweiterte Auftragspositionserfassung

- Fehlerhafte Stornierung bereits vorhandener Positionen beim erneuten Speichern behoben; stornierte Positionen werden aus den Auftrags-, Leistungs- und Rechnungssummen ausgeschlossen.
- Autospeicherungen werden serialisiert, damit verspätete Antworten keine neueren Eingaben überschreiben.
- Die Materialsuche startet ab dem ersten Zeichen und durchsucht Artikelnummer, Kurztext, Langtext, Kategorie und Artikelgruppe mit nachvollziehbarer Relevanzsortierung.
- Vorschläge unterstützen Pfeiltasten, Enter und Escape, kennzeichnen Nummern-/Texttreffer, heben Fundstellen hervor und weisen auf weitere Treffer hin.
- Positionsübersicht um Gesamt netto, eindeutigen MwSt.-Satz, Schnellfilter und Brutto-Zwischensummen nach EL/FK/DP ergänzt.
- Positionsdetails erscheinen direkt unter der gewählten Zeile und enthalten eine persistente Bemerkung; Positionen können dupliziert werden.
- Unvollständige Freitextpositionen und Katalogpositionen ohne Preis werden unmittelbar in der Zeile gekennzeichnet.

## 0.53.3 – Hotfix Datenbankmigration

- Die neue Spalte `article_category` ist für vorhandene Positionszeilen nullable und besitzt keinen unzulässigen Leerstring-Default.
- Eine nach 0.53.2 fehlgeschlagene Migration kann mit dem korrigierten Paket erneut ausgeführt werden.
- Einheit und Positionstyp bleiben geschlossen kompakt und zeigen ihre Bezeichnungen beim Öffnen; Katalog- und Freitextpositionen sind als `KAT` beziehungsweise `FREI` gekennzeichnet.
- Unvollständige Materialnummernsuchen erzeugen keine Position; Auswahl per Maus oder Tastatur füllt die Zeile unmittelbar.
- Freitextpositionen prüfen Preis und Mehrwertsteuer, der Detailzugang steht direkt neben der Bezeichnung.

## 0.53.2 – Korrekturen Auftragserfassung und Leistungssuche

- Datenbankmigration für den Kategorie-Snapshot ist für Bestandszeilen nullable und mit der Nextcloud-Schemaprüfung kompatibel.
- Einheitliche Statusquelle verhindert bearbeitbare Entwurfsanzeigen bei festgeschriebenem Auftrag/KVA oder vorhandener Rechnung.
- Präfixsuche direkt im Materialfeld zeigt bei `AU-` ausschließlich passende Artikelnummern; Materialnummer und Kurztext bleiben unmittelbar auswählbar.
- Die vollständige Suche in Artikelliste/Leistungskatalog bleibt als optionaler Dialog mit Filtern und Seitennavigation erhalten.
- Bezeichnungsspalte verbreitert sowie Kategorie, Gruppe, Kostenart und sprechende Mengeneinheit ergänzt.
- Pflegbare Wertelisten mit festen technischen Schlüsseln für `EL`, `FK`, `DP` und Mengeneinheiten eingeführt.
- Neuer Datenbank-Snapshot für die Artikelkategorie an Auftragspositionen.

## 0.53.1 – SAP-artige Positionsschnellerfassung

- Aufträge im angezeigten Status `Entwurf` wieder vollständig bearbeitbar; Rechnungsschutz bleibt vorrangig erhalten.
- Freitextpositionen behalten ihre bearbeitbare Herkunft auch nach dem Speichern und erneuten Laden.
- Positionsnummern werden verlässlich als `10, 20, 30, ...` geführt.
- Materialnummer steht vor der Bezeichnung und eröffnet die Tabulator-Reihenfolge jeder Position.
- Am Tabellenende steht automatisch eine leere Folgeposition bereit; der Button „Neue Position“ entfällt.

## 0.53.0 – Schlanke Auftrag-/KVA-Positionserfassung

- Kopf- und Positionsdaten in der manuellen Auftrag-/KVA-Erfassung klar getrennt.
- Positionsübersicht mit 10er-Positionierung, Katalog- und Freitextpositionen.
- Automatische Übernahme der Katalogdaten und Positionstypen EL, FK und DP.
- Positionsdetails vorbereitet und der Leistungskatalog als nachgelagerte Auswahl gestaltet.
- Positionsänderungen im Entwurf und unveränderliche Snapshots für die spätere Abrechnung abgesichert.

## 0.52.2 – Sicherungsablage und Aufbewahrung

- Konfigurierbarer, gegen Pfadüberschreitung abgesicherter Sicherungsunterordner innerhalb des Nextcloud-Datenverzeichnisses; Altbestände am bisherigen Speicherort bleiben sichtbar.
- Lokale „Speichern unter …“-Auswahl in unterstützten Chromium-Browsern mit kompatiblem Browserdownload als Fallback.
- Einzelne Sicherungen und ihre Prüfsummendateien können nach administrativer Sicherheitsabfrage gelöscht werden; jede Löschung wird auditiert.
- Getrennte Aufbewahrungsfristen für JSON-Schnellsnapshots und ZIP-Fachpakete sowie bestätigungspflichtige Sammelbereinigung; das letzte gültige vollständige Paket bleibt geschützt.
- Restore-Umfang und Zweck beider Sicherungsarten werden in Oberfläche und Betriebshandbuch eindeutig erläutert.

## 0.52.1 – Hotfix für bestehende Installationen

- Eine noch nicht vollständig bestätigte Ersteinrichtung sperrt den bestehenden Fachbetrieb nicht mehr und erzwingt keinen Wechsel in die Administration.
- Offene Einrichtungspunkte erscheinen nur noch als Hinweis mit direkter Prüfmöglichkeit.
- Nextcloud-Systemadministratoren werden in der Bereitschaftsprüfung als handlungsfähige Administration erkannt; die Zuordnung zu einer konfigurierten Bestatter-Administrationsgruppe bleibt empfohlen.

## 0.52.0 – Ersteinrichtung, Fachsicherung und sicherer Purge

- Geführte Ersteinrichtung für vorhandene Nextcloud-Benutzer, konfigurierbare Gruppen, Ablagepfade, erste Niederlassung und Länderprofil; bis zur erfolgreichen Bereitschaftsprüfung bleibt die Fachnavigation gesperrt.
- Vollständiges ZIP-Fachpaket mit App-Datenbank, App-Konfiguration, Fallakten, Dokumentvorlagen und Artikelbildern sowie Manifest, Datei- und Paketprüfsummen; JSON-Schnellsnapshot bleibt verfügbar.
- Restore akzeptiert JSON und ZIP ausschließlich auf leerem Ziel und prüft vorab Integrität und Dateikollisionen.
- `bestatter:purge` bietet einen unverändernden Trockenlauf und verlangt für die endgültige Bereinigung vollständiges Sicherungspaket, Legal-Hold-Freigabe und festen Bestätigungscode.
- Deaktivierung und normale Deinstallation bleiben datenerhaltend; Benutzer, Gruppen, Sicherungen und externe Paperless-Originale werden nicht entfernt.

## 0.51.1 – Sichere Downloads und Backup-/Restore-Cockpit

- Fallexport, Feldkatalog und CSV-Berichte senden den Nextcloud-CSRF-Token jetzt über einen gemeinsamen, geschützten Downloadweg.
- Administration um „Backup & Restore“ ergänzt: App-Snapshot erstellen, Integrität prüfen, Sicherungen auflisten und herunterladen.
- Wiederherstellung bleibt bewusst auf ein leeres, isoliertes Ziel, OCC-Vorschau und expliziten Bestätigungscode beschränkt.
- Gleichzeitige Backups erhalten kollisionsfreie Dateinamen; gelistete Dateien sind strikt auf verwaltete Bestatter-Snapshots begrenzt.

## 0.51.0 – Länderprofile und Österreich-Basis

- Länderprofile zentral administrierbar gemacht; der gemeinsame Artikelkatalog verwendet die freigegebenen Steuersätze der aktiven Profile.
- Niederlassungen um ISO-Ländercode, Rechnungsprofil, Zahlungs-QR-Standard und österreichisches Bundesland erweitert.
- Deutschland als migrationssicheren Standard für bestehende Niederlassungen beibehalten.
- Österreich mit 0/10/13/20 Prozent, neun Bundesländern und EPC-Zahlcode technisch vorbereitet.
- Rechnungserzeugung an das Niederlassungsprofil gekoppelt; deutsche E-Rechnungsformate werden für Österreich nicht implizit angenommen.
- Regionalen Vorlagen-Namensraum eingeführt. Behördenabhängige Dokumente brechen ohne freigegebene Regionalvorlage verständlich ab; neutrale Dokumente bleiben nutzbar.
- Entwicklungs-, Upgrade- und Länderdokumentation konsolidiert.

## 0.50.0 – konsolidierte Entwicklungsbasis

- Vollständiger Funktionsstand der Entwicklungsreihe bis einschließlich 0.49.2 als gemeinsame Basis für die geplanten Länderversionen.
- Rechnungs-Prüfdokumente werden im Erzeugungsdialog ausdrücklich als Prüfdokument bezeichnet; die lang laufende DOCX-/PDF-Konvertierung zeigt Fortschrittsbalken, Laufzeit und verständliche Zwischenhinweise.
- Der EPC-SEPA-Zahlcode wird serverseitig validiert, in die Rechnungsquelle eingearbeitet und in der unveränderlichen PDF-Ausgabe ausgegeben.
- Die Entwicklungsmigrationen 1900 bis 2900 wurden in die idempotente Installationsbaseline 1800 übernommen. Es hat noch keine öffentliche Veröffentlichung gegeben; deshalb beginnt der künftige Upgradepfad mit diesem konsolidierten Stand.
- Veraltete Upgrade-, Abnahme- und Paketartefakte aus Zwischenversionen wurden entfernt. Fachkonzepte, Betriebshandbuch, OP-Liste und Regressionstests bleiben erhalten.

Die fachliche Dokumentation befindet sich themenbezogen im Ordner `docs`. Der interne Entwicklungs-, Test- und Migrationsweg ist in `docs/ENTWICKLUNG.md` zusammengefasst.
