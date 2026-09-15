# Konzept: Eingangsrechnungen und fallbezogene Fremdkosten

## Ziel und Abgrenzung

Die Bestatter-App bildet vorerst den Prozess bis zur Erstellung und Festschreibung einer oder mehrerer Ausgangsrechnungen ab. Externe Rechnungen werden nur so weit verarbeitet, wie ihre Positionen einem Sterbefall zugeordnet, fachlich geprüft und kontrolliert in eine Ausgangs- oder Teilrechnung übernommen werden müssen. Der Grundprozess funktioniert vollständig innerhalb von Nextcloud und der Bestatter-App; Paperless-ngx ist eine spätere optionale Integrationsstufe.

In Stufe 1 wird der Originalbeleg direkt in den Nextcloud-Fallordner `06 Abrechnung` hochgeladen. Die Bestatter-App verwaltet Prüfsumme, Belegkopf, Positionen, Soll-Ist-Abgleich und Übernahme. OCR, E-Mail-/Scanner-Eingang und Volltextarchiv werden bewusst nicht nachgebaut. Diese Komfortfunktionen können später durch Paperless-ngx ergänzt werden. Die Bestatter-App bleibt immer das führende System für Fall, Auftrag/KVA, fachliche Prüfung, Leistungen und Kundenrechnung.

Nicht Bestandteil dieses Entwicklungsabschnitts sind Kreditorenzahlungen, Überwachung von Zahlungseingängen, offene Posten, Mahnwesen, Rechnungsstorno, Gutschriften und vollständige Finanzbuchhaltung. Diese Funktionen bleiben OP-Themen. Eine spätere Übergabe an ERPNext oder eine vorhandene Finanzbuchhaltung wird über eine stabile Schnittstelle vorbereitet, aber aktuell nicht umgesetzt.

## Architekturentscheidung: eigenständiger Kern, optionale Spezialdienste

### Stufe 1 – ohne Paperless-ngx (umgesetzt in 0.38.0)

- manueller Upload von PDF, Bild oder XML in den Nextcloud-Fallordner;
- Belegkopf und beliebig viele Positionen erfassen und korrigieren;
- Dublettenprüfung über normalisierten Lieferanten plus Rechnungsnummer sowie SHA-256 der Datei;
- Beleg- und Positionssumme auf Centgenauigkeit abstimmen;
- Lieferantenmenge/-preis/-steuer getrennt von Kundenmenge/-preis/-steuer speichern;
- vorhandene Fallleistung zuordnen oder einmalige Zusatzleistung erzeugen;
- Abweichungen zwingend begründen und Positionen steuerlich/fachlich klassifizieren;
- einmalige, transaktionale Übernahme mit Benutzer, Zeitpunkt und Audit-Nachweis;
- Status `ENTWURF`, `PRUEFUNG`, `ZUGEORDNET`, `FREIGEGEBEN` oder `ABGELEHNT`.

### Stufe 2 – Paperless-ngx (Connector und Belegeingang in 0.48.0 umgesetzt)

Paperless-ngx übernimmt:

- Eingang über E-Mail-Regeln, Scanner-/Consume-Verzeichnis, Web-Upload oder API;
- Aufbewahrung des unveränderten Originals und gegebenenfalls einer durchsuchbaren Archivfassung;
- lokale OCR, Volltextsuche, Vorschau und Dokumenthistorie;
- Lieferanten als Korrespondenten, Dokumenttyp `Eingangsrechnung`, Schlagworte und benutzerdefinierte Felder;
- automatische Zuordnung über Workflows sowie Benachrichtigung der Bestatter-App per Webhook;
- rollen- und dokumentbezogene Berechtigungen.

Paperless-ngx ist dann für Dokumenteingang, OCR, Volltext und Archivmetadaten zuständig, niemals für den kaufmännischen Positionsabgleich oder die Fakturierung. Die bestehende manuelle Nextcloud-Ablage bleibt als Fallback erhalten.

Dokumentation: <https://docs.paperless-ngx.com/usage/> und <https://docs.paperless-ngx.com/api/>.

### Mustangproject

Mustangproject übernimmt bei XRechnung und ZUGFeRD:

- Extraktion des eingebetteten oder eigenständigen Rechnungs-XML;
- technische Validierung von ZUGFeRD/Factur-X, CII und XRechnung;
- strukturierte Bereitstellung von Lieferant, Rechnungsnummer, Datum, Währung, Steuergruppen, Summen und Positionen.

Die ausgelesenen Daten wären ausschließlich Vorschläge. Vor einer Übernahme in die Fallkosten muss ein Benutzer sie gegen das sichtbare Original prüfen. In Stufe 1 werden Positionen manuell erfasst; eine spätere OCR- oder E-Rechnungs-Extraktion darf diese Prüfung nicht ersetzen.

Projekt: <https://github.com/ZUGFeRD/mustangproject>.

### Bestatter-App

Das fachliche Modul `Eingangsrechnungen` übernimmt unabhängig von einem Dokumentenmanagementsystem:

- Originalbeleg anhand von Nextcloud-Datei-ID und SHA-256 stabil am Fall verknüpfen;
- Kopf- und Positionsdaten anzeigen und bestätigen;
- vorhandene Fallleistungen zuordnen;
- Mengen-, Preis- und Steuerabweichungen gegenüber Auftrag/KVA hervorheben;
- ungeplante Positionen als einmalige fallbezogene Zusatzleistung anlegen;
- echte durchlaufende Posten von weiterberechenbaren Fremdleistungen trennen;
- freigegebene Positionen für Teil- oder Schlussrechnungen bereitstellen;
- doppelte Belegerfassung und doppelte Fakturierung verhindern.

### Spätere Finanzbuchhaltung

ERPNext ist eine mögliche spätere Open-Source-Zielanwendung für Lieferanten, Bestellungen, Wareneingänge, Kreditorenrechnungen, Kostenstellen, Projekte und Buchhaltung. Ein Bestattungsfall könnte dort über Projekt oder Buchungsdimension referenziert werden. ERPNext wird jetzt nicht parallel als zweites führendes Fall- oder Rechnungssystem eingeführt, da dies Stammdaten verdoppeln und den aktuellen Umfang unnötig erweitern würde.

Dokumentation: <https://docs.frappe.io/erpnext/purchase-invoice> und <https://docs.frappe.io/erpnext/project-profitability>.

## Führende Systeme und Dokumenthaltung

- In Stufe 1 führt Nextcloud den unveränderten Originalbeleg im Fallordner; die Bestatter-App führt Prüfsumme, Belegmetadaten, Fallzuordnung, Bearbeitungsstatus und Positionszuordnungen.
- Ab 0.48.0 bleibt das unabhängig verfügbare Original immer zusätzlich im Nextcloud-Fallordner. Paperless führt OCR-, Vorschau-, Volltext- und Archivmetadaten; die Bestatter-App speichert Paperless-ID, Nextcloud-Datei-ID und Prüfsumme als gemeinsame Quellenkette.
- Es wird keine zweite bearbeitbare Originalfassung erzeugt. Änderungen an Metadaten oder Zuordnungen verändern weder den Originalbeleg noch historische Auftragssnapshots.
- Löschen oder Ersetzen eines Originalbelegs erfolgt ausschließlich nach einem gesonderten Berechtigungs- und Aufbewahrungskonzept.

## Wichtige steuerliche Unterscheidung

„Durchlaufender Posten“ darf nicht als allgemeines Synonym für jede weiterberechnete Fremdrechnung verwendet werden. Nach § 10 UStG liegt er nur vor, wenn Beträge im Namen und für Rechnung eines anderen vereinnahmt oder verausgabt werden. Eine an das Bestattungsunternehmen adressierte Lieferantenrechnung ist deshalb nicht automatisch ein durchlaufender Posten. Die verbindliche Klassifikation je Gebühren-/Leistungsart muss mit der Steuerberatung festgelegt werden.

Die Anwendung unterscheidet mindestens:

- `TRUE_PASS_THROUGH`: steuerlich bestätigter echter durchlaufender Posten;
- `THIRD_PARTY_SERVICE`: eingekaufte und an den Auftraggeber weiterberechnete Fremdleistung;
- `EXPENSE_FEE`: Auslage oder Gebühr, die nicht als echter durchlaufender Posten bestätigt ist;
- `NOT_BILLABLE`: betrieblicher Aufwand ohne Weiterberechnung;
- `CLASSIFICATION_PENDING`: steuerliche/fachliche Einordnung noch offen.

Quelle: <https://www.gesetze-im-internet.de/ustg_1980/__10.html>.

## Paperless-Konfiguration ab 0.48.0

Vorgesehene Objekte:

- Dokumenttyp: `Eingangsrechnung`;
- Korrespondent: Lieferant/Rechnungsaussteller;
- Schlagworte: `Bestatter`, `Eingangsrechnung`, `Ungeprüft`, `Fall zugeordnet`, `Zur Abrechnung freigegeben`;
- benutzerdefinierte Felder: `Fallnummer`, `Lieferanten-Rechnungsnummer`, `Rechnungsdatum`, `Fälligkeitsdatum`, `Währung`, `Netto`, `Umsatzsteuer`, `Brutto`, `Bestatter-Belegstatus`, `Bestatter-Beleg-ID`;
- Workflow: Nach Verarbeitung einer Eingangsrechnung POST-Webhook an einen authentifizierten Bestatter-Endpunkt;
- Eingangskanäle: eigenes Rechnungs-Postfach, Scanner/Consume-Verzeichnis, manueller Upload und Upload aus der Bestatter-App über die Paperless-API.

Der Webhook enthält nur notwendige technische Daten, insbesondere Paperless-ID und Ereignisart. Die Bestatter-App liest Dokument und Metadaten anschließend authentifiziert über die API. Zugangsdaten werden ausschließlich serverseitig als Secret gespeichert und niemals an den Browser ausgeliefert.

## Datenmodell der Bestatter-App

### Eingangsbeleg

- interne ID und zugeordnete Fall-ID;
- Nextcloud-Datei-ID, Dokumentdatensatz, Dateipfad und SHA-256-Prüfsumme;
- Lieferant und dessen normalisierte Bezeichnung;
- externe Rechnungsnummer, Rechnungsdatum, Eingangsdatum und Fälligkeit;
- Währung sowie Kontrollsummen netto, Umsatzsteuer und brutto;
- Status `ENTWURF`, `PRUEFUNG`, `ZUGEORDNET`, `FREIGEGEBEN` oder `ABGELEHNT`;
- Quelle `BESTATTER_UPLOAD`; spätere Paperless-Referenzen sind erweiterbar;
- Ersteller, letzter Bearbeiter und Zeitstempel;
- Dublettenprüfung mindestens über Lieferant, externe Rechnungsnummer und Dokument-Prüfsumme.

### Eingangsbelegposition

- Positionsnummer und Bezeichnung laut Fremdrechnung;
- Menge, Einheit, Ist-Einkaufspreis sowie Netto-, Steuer- und Bruttobetrag;
- Zuordnung zu genau einem Fall und optional einer vorhandenen Fallleistung;
- Klassifikation `TRUE_PASS_THROUGH`, `THIRD_PARTY_SERVICE`, `EXPENSE_FEE`, `NOT_BILLABLE` oder `CLASSIFICATION_PENDING`;
- eigener Verkaufstext, freigegebener Verkaufsbetrag und Steuersatz für die Kundenrechnung;
- Abweichungsstatus für Menge, Preis, Steuersatz oder unbekannte Position sowie Pflichtbegründung;
- Kennzeichen, in welcher Ausgangsrechnung und mit welcher Teilmenge die Position berücksichtigt wurde.

Katalogpreis, Auftragssnapshot, tatsächlicher Lieferantenbetrag und späterer Rechnungsbetrag bleiben getrennte Werte. Ein Eingangsbeleg darf historische Auftrags- oder Katalogwerte nicht rückwirkend überschreiben.

## Prozessablauf

### Fachliche Ausgangsbasis und Abgrenzung

Die Bestatter-App besitzt bereits zuordenbare **Fallleistungen**. Sie entstehen aus der im KVA beziehungsweise Auftrag ausgewählten Leistungs- und Artikelliste und werden als unveränderlicher Auftragssnapshot je Fall geführt. Eine Eingangsrechnungsposition kann deshalb bereits heute:

- einer vorhandenen beauftragten Fallleistung zugeordnet werden;
- deren tatsächliche Fremdmenge beziehungsweise Fremdkosten dokumentieren;
- oder, wenn vorher nichts beauftragt wurde, kontrolliert eine einmalige Zusatzleistung nur für diesen Fall erzeugen.

Noch nicht vorhanden ist ein eigenständiger Lieferanten-Beschaffungsprozess mit Lieferantenbestellung, Bestellnummer, Bestellformular, Bestellstatus und bestätigtem Wareneingang. Der gegenwärtige Abgleich ist daher bewusst ein **Rechnung–Fallleistung–Kundenabrechnung-Abgleich** und kein vollständiger Drei-Wege-Abgleich aus Bestellung, Wareneingang und Rechnung. Der spätere Ausbau ist als OP-023 dokumentiert.

### Geführte Bedienung ab 0.39.0

Die Oberfläche führt den Anwender sichtbar durch fünf fachliche Stationen:

1. **Beleg:** Originaldatei hochladen und unverändert unter `06 Abrechnung` ablegen; Dublettenprüfung über Datei sowie Lieferant/Rechnungsnummer.
2. **Rechnungsdaten:** Lieferant, Rechnungsnummer, Daten und Belegsumme erfassen beziehungsweise prüfen.
3. **Positionsabgleich:** Lieferantenwerte exakt vom Beleg erfassen und anschließend je Position einer vorhandenen Auftrags-/Fallleistung zuordnen oder als einmalige Zusatzleistung kennzeichnen.
4. **Prüfung und Übernahme:** Summendifferenz, ungeklärte Klassifikationen, Neuanlagen und begründete Abweichungen werden vor der einmaligen Übernahme zusammengefasst.
5. **Freigabe:** Erst der bewusst ausgelöste Statuswechsel gibt die übernommenen Fallleistungen für den weiteren Rechnungsprozess frei.

Jede Belegkarte zeigt denselben Ablauf als Fortschrittsanzeige und nennt genau den fachlich nächsten Schritt. Technische Statuswerte werden für den Anwender in sprechende Bezeichnungen übersetzt. Der Entwurf darf unvollständig gespeichert werden; die Übernahme bleibt bei Summendifferenzen oder ungeklärten Klassifikationen gesperrt.

1. Der Benutzer öffnet den betreffenden Fall und wählt unter Finanzen `+ Eingangsrechnung`.
2. Er lädt das Original hoch; die App legt es unter `06 Abrechnung` ab und prüft Datei sowie Lieferant/Rechnungsnummer auf Dubletten.
3. Kopf- und Positionsdaten werden erfasst, geprüft und bei Bedarf korrigiert, ohne das Original zu verändern.
4. Jede Position wird einer vorhandenen Fallleistung zugeordnet oder als einmalige Zusatzposition angelegt.
5. Die App zeigt den Soll-Ist-Abgleich:
   - bestellt gegenüber geliefert/berechnet;
   - erwarteter gegenüber tatsächlichem Einkaufspreis;
   - geplanter gegenüber freizugebendem Kundenpreis;
   - erwartete gegenüber berechnete Menge und Steuer.
6. Abweichungen werden begründet. Unbekannte Positionen werden nicht automatisch in den globalen Artikelkatalog übernommen.
7. Nach Summenabgleich und Klassifikation werden alle Positionen genau einmal transaktional übernommen; `CLASSIFICATION_PENDING` blockiert die Übernahme.
8. Übernommene Positionen erscheinen als noch abzurechnende Fallleistungen und der Beleg kann freigegeben werden.
9. Beim Rechnungsentwurf werden Positionen und Teilmengen ausdrücklich ausgewählt.
10. Die festgeschriebene Ausgangsrechnung speichert unveränderliche Positionssnapshots und Quellenverweise zur Eingangsbelegposition.

Mit OP-022 können die Schritte 1 und 2 später durch Paperless-Eingang, OCR-Vorschläge und Webhook/API-Import ergänzt werden. Die Schritte 3 bis 10 und die Benutzerprüfung bleiben unverändert.

## Teilrechnungen

Das bestehende Mengenmodell mit `performed_quantity` und `invoiced_quantity` bleibt Grundlage. Ergänzt werden:

- Auswahl der Positionen je Rechnungsentwurf;
- frei wählbare Teilmenge bis zur noch abrechenbaren Menge;
- Rechnungsart `RECHNUNG`, `TEILRECHNUNG` und optional `SCHLUSSRECHNUNG`;
- Leistungs-/Abrechnungszeitraum und verständlicher Hinweis auf bereits fakturierte Mengen;
- Restmengenanzeige nach jeder festgeschriebenen Rechnung;
- Sperre gegen doppelte Fakturierung derselben Menge oder Eingangsbelegposition.

## Fehler- und Sicherheitskonzept

- Ein optionaler Paperless- oder Mustang-Ausfall verhindert nicht die Fallbearbeitung; die manuelle Erfassung aus Stufe 1 bleibt verfügbar.
- Zukünftige Webhooks müssen idempotent über Paperless-ID und Ereigniskennung verarbeitet werden; API-Abrufe benötigen Zeitlimit, Wiederholungslogik und nachvollziehbare Fehlermeldungen.
- Kein automatischer externer Versand und keine automatische Rechnungsfreigabe.
- OCR- oder E-Rechnungsdaten werden nie ungeprüft fakturiert.
- Jeder Statuswechsel, jede Zuordnung und jede Preisabweichung wird mit Benutzer und Zeitpunkt protokolliert.
- Zugriff erhalten nur Bestatter-Mitglieder mit der jeweils notwendigen Rolle; Paperless-Berechtigungen müssen dieses Modell spiegeln.

## Umsetzung in Stufen

### Stufe 1 – Eigenständiger Belegkopf und Positionen (0.38.0)

- Nextcloud-Upload, Fallzuordnung und Öffnen des Originalbelegs;
- Belegkopf, Status, Dublettenprüfung und Audit-Log;
- manuelle Positionserfassung, Soll-Ist-Abgleich und Klassifikation;
- einmalige Übernahme in vorhandene oder neue Fallleistungen.

### Stufe 2 – Optionale Paperless-Anbindung (0.48.0)

- serverseitige Paperless-Konfiguration und Verbindungstest;
- Webhook/API-Import, zentrale Arbeitsliste und doppelt abgesicherte Originalreferenz;
- verschlüsselte Zugangsdaten, Retry-Warteschlange und Systemprüfung;
- Fallback auf den Prozess aus Stufe 1 bei Nichtinstallation oder Ausfall.

OCR-Metadaten und Rechnungs-/Positionsvorschläge werden erst nach erfolgreicher Integrationsabnahme mit 0.49 ergänzt.

### Stufe 3 – Vertiefung der Teilrechnungen und Quellenkette

- explizite Auswahl von Positionen und Teilmengen;
- Quellenkette bis zum Nextcloud- beziehungsweise optionalen Paperless-Original;
- Restmengen, Fakturierungssperren und unveränderliche Rechnungssnapshots.

### Später – Buchhaltungsschnittstelle

- Export/API zu ERPNext oder vorhandener Finanzbuchhaltung;
- Kreditorenbuchung, Zahlungen, offene Posten, Mahnwesen, Storno und Gutschriften;
- außerhalb des aktuellen Entwicklungsumfangs.

### Optional später – formale Lieferantenbestellung (OP-023)

- Bestellung aus ausgewählten Fremdleistungen des Auftrags erzeugen;
- Lieferant, Bestellnummer, Bestellpositionen, Mengen, vereinbarte Einkaufspreise und Termine festschreiben;
- Bestellformular als DOCX/PDF im Fallordner ablegen, jedoch keinen automatischen Versand ohne gesonderte Freigabe;
- Wareneingang beziehungsweise Leistungserbringung mit Teilmengen dokumentieren;
- Eingangsrechnung wahlweise im Zwei-Wege-Abgleich gegen die Bestellung oder im Drei-Wege-Abgleich gegen Bestellung und Leistungseingang prüfen.

Diese Erweiterung ist nur sinnvoll, wenn Bestellungen tatsächlich aus der App heraus geführt werden sollen. Für Rechnungen von Behörden, Friedhöfen oder ungeplante Fremdkosten bleibt die Zuordnung zu einer Fallleistung beziehungsweise Zusatzleistung weiterhin notwendig.

## Bewertung des aktuellen Entwicklungsstands

Bereits verwendbar ab 0.38.0:

- Artikelstammdaten mit Fremdleistung, Auslage/Gebühr, Lieferant sowie Einkaufs- und Verkaufspreis;
- fallbezogene Auftragssnapshots;
- Erfassung abweichender erbrachter Mengen mit Warnung und Begründung;
- Trennung von erbrachter, bereits fakturierter und noch abrechenbarer Menge;
- mehrere zeitlich aufeinanderfolgende Ausgangsrechnungen;
- Upload und fallbezogene Ablage einer externen Rechnungsdatei;
- strukturierter Eingangsbeleg mit Kopf- und Positionsdaten;
- Ist-Einkaufspreis, Kundenwert und Soll-Ist-Abgleich je Position;
- Zuordnung zu vorhandener Fallleistung oder einmalige Zusatzposition;
- fachlich getrennte Behandlung echter durchlaufender Posten;
- Dubletten- und Übernahmesperre samt Audit.

Noch umzusetzen beziehungsweise zurückgestellt:

- Mustang-Anbindung für XRechnung/ZUGFeRD;
- OCR-/Metadatenübernahme und Positionsvorschläge aus Paperless (0.49 nach Abnahme);
- explizite Auswahl von Positionen und Teilmengen je Teilrechnung.

## Abnahmekriterien für Stufe 1 / 0.38.0

- Eine Rechnung kann mit Originaldatei erfasst, eindeutig einem Fall zugeordnet und in Nextcloud geöffnet werden.
- Dieselbe Belegdatei oder dieselbe Lieferanten-Rechnungsnummer je Lieferant wird nicht doppelt angelegt.
- Vorhandene, abweichende und ungeplante Positionen können ohne Änderung historischer Auftragsdaten verarbeitet werden.
- Jede weiterberechnete Position verweist über den Eingangsbeleg bis zum unveränderten Nextcloud-Original zurück.
- Nicht abschließend klassifizierte oder nicht summengleiche Positionen können nicht übernommen werden.
- Eine Position kann auch bei parallelen Benutzerzugriffen nur einmal übernommen werden.
- Mehrere Teilrechnungen sind möglich, ohne Mengen oder Eingangsbelegpositionen doppelt zu berechnen.

Paperless-Import und Quellenkette sind ab 0.48.0 separat abzunehmen. OCR, XRechnung/ZUGFeRD-Extraktion und Positionsvorschläge bleiben bis zur anschließenden Version 0.49 zurückgestellt.
