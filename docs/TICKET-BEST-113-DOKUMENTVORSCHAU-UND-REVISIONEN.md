# BEST-113 – Dokumentvorschau, Druck und revisionssichere Erzeugung von Auftrag/KVA/Vollmacht

## Einordnung

- **Priorität:** Muss
- **Aufwand:** L
- **Betroffene Bereiche:** Fallakte → Auftrag / KVA, Dokumente, Dokumentvorlagen, Fallordner `02 Auftrag`, Audit-Protokoll
- **Ausgangsversion:** geprüft auf dem Entwicklungsstand nach 0.56.0
- **Umsetzungsstand:** Stufe 1 umgesetzt – nebenwirkungsfreie PDF-Vorschau und atomarer aktueller Entwurf; fortlaufende finale Revisionen sind noch offen.

## Anlass

Der Button **„Drucken“** in der Auftrag-/KVA-Erfassung erzeugt derzeit keine fachliche Dokumentansicht. Er ruft unmittelbar `window.print()` auf und übergibt damit die komplette, interaktive Erfassungsmaske an den Browserdruck. Das Ergebnis enthält nicht das verbindliche Layout der Dokumentvorlage und ist wegen ausgeblendeter, abgeschnittener oder leerer Maskenbereiche kein sinnvoller Ausdruck.

Der Button **„Auftrag & Vollmacht erzeugen“** speichert zunächst Auftrag und Positionen und ruft anschließend ohne Vorschau den Endpunkt `/cases/{id}/order-documents` auf. Im KVA-Modus wird `BESTATTUNGSAUFTRAG`, im Auftragsmodus werden `BESTATTUNGSAUFTRAG` und `BESTATTUNGSVOLLMACHT` erzeugt. Die Oberfläche springt danach lediglich in den Dokumentbereich.

Bei wiederholter Erzeugung wird gegenwärtig keine echte Dokumentversion angelegt:

- Der Status ist standardmäßig `ENTWURF`, die technische Versionsnummer ist fest auf `1` gesetzt.
- Vorhandene Ausgaben derselben Vorlage und desselben Kontextes werden im Fallordner gelöscht und neu erzeugt.
- Der zugehörige Dokumentdatensatz wird aktualisiert statt als neue Revision fortgeschrieben.
- Finale, unterschriebene oder versendete Dokumentdatensätze sind bereits serverseitig gegen Bearbeitung und Löschung geschützt. Gleichnamige finale oder unterschriebene Dateien blockieren außerdem eine erneute Generierung.
- Die gemeinsame Erzeugung von Auftrag und Vollmacht läuft nacheinander. Schlägt das zweite Dokument fehl, ist derzeit kein fachlich atomarer Paketabschluss garantiert.

Damit sind „Vorschau“, „Arbeitsentwurf“, „finale Ausgabe“ und „Korrektur einer finalen Ausgabe“ für Anwender nicht ausreichend getrennt.

## Zielbild

Die Dokumentausgabe folgt einem klaren dreistufigen Modell:

1. **Vorschau:** Eine schreibfreie, temporäre PDF-Vorschau aus den aktuell gespeicherten Kopf- und Positionsdaten. Sie legt weder eine Datei im Fallordner noch einen Dokumentdatensatz oder eine Versionsnummer an.
2. **Aktueller Entwurf:** Pro Dokumentart und fachlichem Kontext existiert genau ein bearbeitbarer Arbeitsentwurf. Eine erneute Erzeugung ersetzt ausschließlich diesen Entwurf nach verständlicher Bestätigung und wird auditiert.
3. **Festgeschriebene Revision:** Finale, unterschriebene oder versendete Ausgaben sind unveränderlich und nicht löschbar. Eine fachlich notwendige Korrektur erzeugt eine neue Revision mit fortlaufender Nummer und Bezug auf die ersetzte Revision; die alte Ausgabe bleibt sichtbar.

Der bisherige Button **„Drucken“** wird durch **„Vorschau“** ersetzt. Gedruckt wird ausschließlich aus der PDF-Dokumentvorschau beziehungsweise einer erzeugten PDF-Ausgabe, niemals aus der Auftragserfassungsmaske.

## Vorgeschlagene Bedienung

### Auftrag / KVA

- Der primäre Einstieg lautet abhängig vom Modus **„KVA-Vorschau“** oder **„Auftrag und Vollmacht prüfen“**.
- Vor dem Öffnen werden laufende Autospeicherungen abgeschlossen und die vorhandenen Plausibilitätsregeln für Kopf- und Positionsdaten ausgeführt.
- Ein breiter, schreibgeschützter Vorschaudialog zeigt das tatsächliche A4-Dokumentlayout. Im Auftragsmodus können **Auftrag** und **Vollmacht** innerhalb desselben Dialogs umgeschaltet werden.
- Der Dialog bietet: **„Vorschau aktualisieren“**, **„Entwurf erzeugen/aktualisieren“**, **„PDF öffnen / drucken“** und **„Schließen“**. „PDF öffnen / drucken“ arbeitet mit der Vorschau-PDF und erzeugt keine dauerhafte Dokumentrevision.
- Existiert bereits ein Entwurf, lautet die Aktion **„Entwurf aktualisieren“** und zeigt vor der Bestätigung Datum, Benutzer und betroffene Dokumente.
- Existiert bereits eine finale Ausgabe, wird keine Überschreibaktion angeboten. Berechtigte Benutzer können stattdessen **„Neue Revision anlegen“** wählen; ein Korrekturgrund ist Pflicht.

### Dokumentbereich

Die Liste „Erstellte Ausgaben“ zeigt mindestens:

- Dokumentart und fachlicher Kontext,
- Status (`ENTWURF`, `FINAL`, `UNTERSCHRIEBEN`, `VERSENDET`, `ERSETZT`),
- Revisionsnummer,
- erstellt/geändert von und Zeitpunkt,
- gegebenenfalls „ersetzt Revision …“ beziehungsweise „ersetzt durch Revision …“,
- passende Aktionen: Vorschau, DOCX bearbeiten (nur Entwurf), PDF öffnen/drucken, neue Revision anlegen.

Ein Entwurf darf mit Schutzabfrage gelöscht werden. Festgeschriebene Revisionen bleiben wie bisher auch über direkte API-Aufrufe nicht löschbar.

## Fachliche Regeln

- **KVA-Modus:** Das Dokumentpaket enthält nur den Kostenvoranschlag auf Basis der Vorlage `BESTATTUNGSAUFTRAG`.
- **Auftragsmodus:** Das Dokumentpaket enthält Bestattungsauftrag und Bestattungsvollmacht.
- Auftrag und Vollmacht bilden bei gemeinsamer Erzeugung eine Paketrevision. Entweder werden beide erfolgreich erzeugt und registriert oder keine der beiden Ausgaben wird als erfolgreich erstellt ausgewiesen.
- Vorschau und dauerhafte Erzeugung verwenden denselben Befüllungs- und PDF-Konvertierungspfad, damit Inhalt und Layout übereinstimmen.
- Revisionsnummern werden serverseitig und transaktionssicher je Fall, Dokumentart und Kontext vergeben; eine Dateisuche oder die derzeit fest codierte `version = 1` genügt nicht.
- Eine neue finale Revision überschreibt keine Datei. Der Dateiname enthält eine eindeutige Revisionsangabe, zum Beispiel `… - Final - R02.pdf`.
- Nur eine Revision ist fachlich „aktuell“. Vorgänger erhalten den Status `ERSETZT`, bleiben aber unverändert abrufbar.
- Der Grund einer neuen Revision, Benutzer, Zeitpunkt, Vorgänger und Nachfolger werden im Audit-Protokoll gespeichert.
- Doppelklicks und wiederholte identische Requests dürfen keine doppelten Revisionen erzeugen. Während der Erzeugung sind die Aktionen gesperrt und zeigen einen Fortschrittsstatus.

## Technischer Umsetzungsvorschlag

1. `window.print()` am Auftrag entfernen und den vorhandenen Dokumentdialog sowie `showFilePreview()` als UI-Grundlage wiederverwenden.
2. Einen Vorschau-Endpunkt ergänzen, der aus demselben Renderingpfad wie `DocumentService::generateTemplate()` eine temporäre PDF-Antwort beziehungsweise kurzlebige Vorschau-ID liefert, ohne `RecordService::saveDocument()` und ohne Ablage im Fallordner.
3. `DocumentService::generateOrderDocuments()` in eine Paketoperation überführen. Dateierzeugung und Datensatzanlage werden vorbereitet und bei Teilfehlern vollständig zurückgerollt beziehungsweise kompensierend bereinigt.
4. Dokumentrevisionen mit einer serverseitigen Sequenz und den Feldern `revision`, `predecessorRecordId`, `replacementReason`, `packageId` und `isCurrent` modellieren. Die Eindeutigkeit wird in der Datenbank abgesichert.
5. `RecordService::saveDocument()` trennt künftig die Operationen „aktuellen Entwurf aktualisieren“ und „neue unveränderliche Revision erzeugen“. Die bestehenden Lösch- und Bearbeitungssperren für `FINAL`, `UNTERSCHRIEBEN` und `VERSENDET` bleiben erhalten und werden um `ERSETZT` ergänzt.
6. Für die Erzeugungsanforderung eine Idempotenzkennung verwenden und den UI-Button bis zum Abschluss deaktivieren.
7. Die allgemeine Dokumentvorlagenansicht und den Auftrag-/KVA-Arbeitsbereich auf dieselben Statusbezeichnungen und Aktionen vereinheitlichen.

## Akzeptanzkriterien

1. **Vorschau statt Maskendruck:** Der bisherige Button „Drucken“ heißt „Vorschau“ und öffnet das tatsächliche A4-Dokumentlayout. Die Erfassungsmaske wird nicht gedruckt.
2. **Keine Nebenwirkung:** Öffnen, Aktualisieren, Schließen und Drucken der Vorschau erzeugen weder einen permanenten Fallordner-Eintrag noch einen Dokumentdatensatz oder eine Revision.
3. **Datenkonsistenz:** Die Vorschau enthält nach abgeschlossener Speicherung exakt die aktuellen Kopf-, Positions-, Summen- und Hinweisdaten und verwendet dieselbe Renderlogik wie die spätere Ausgabe.
4. **Passender Dokumentumfang:** Im KVA-Modus wird genau ein KVA angezeigt/erzeugt; im Auftragsmodus werden Auftrag und Vollmacht als zusammengehöriges Paket angezeigt/erzeugt.
5. **Entwurfsregel:** Eine wiederholte Entwurfserzeugung aktualisiert nach Bestätigung ausschließlich den aktuellen Entwurf. Die Oberfläche erklärt vorab, dass keine neue Revision entsteht, und protokolliert die Aktualisierung.
6. **Unveränderliche Historie:** Finale, unterschriebene, versendete und ersetzte Ausgaben können weder über die Oberfläche noch über die API überschrieben, bearbeitet oder gelöscht werden.
7. **Neue Revision statt Überschreiben:** Nach einer finalen Ausgabe kann nur eine neue fortlaufende Revision mit Pflichtbegründung angelegt werden. Vorgänger und Nachfolger sind wechselseitig nachvollziehbar und die alte Datei bleibt öffnbar.
8. **Atomare Paketerzeugung:** Bei Auftrag plus Vollmacht werden entweder beide Dokumente mit derselben Paket-/Revisionskennung erfolgreich angelegt oder der Vorgang endet ohne halbfertiges Paket und mit verständlicher Fehlermeldung.
9. **Doppelklickschutz:** Mehrfaches Klicken oder Wiederholen desselben Requests erzeugt höchstens einen Entwurf beziehungsweise eine Revision.
10. **Transparenter Status:** Vor und nach der Aktion sind aktueller Dokumentstatus, Revision, Zeitpunkt und verfügbare Folgeaktion sichtbar; nach Erfolg kann jedes erzeugte Dokument unmittelbar geöffnet werden.
11. **Berechtigungen:** Nur berechtigte Rollen dürfen Entwürfe aktualisieren, finale Revisionen anlegen oder einen Korrekturgrund erfassen. Leseberechtigte dürfen Vorschau und bestehende Ausgaben nur öffnen.
12. **Barrierearme Bedienung:** Vorschaudialog und Dokumentumschaltung sind vollständig per Tastatur bedienbar, halten den Fokus im Dialog und geben Lade-, Fehler- und Erfolgszustände über `aria-live` aus.

## Testfälle

1. KVA-Entwurf mit mehreren Positionstypen öffnen: Vorschau prüfen, schließen und sicherstellen, dass Fallordner und Dokumentliste unverändert bleiben.
2. Auftrag mit vollständigen Daten öffnen: Auftrag und Vollmacht in der Vorschau umschalten; beide müssen denselben Datenstand zeigen.
3. Einen Entwurf erzeugen und erneut erzeugen: Bestätigungsdialog prüfen; es bleibt genau ein aktueller Entwurf je Dokumentart bestehen, Audit-Eintrag wird ergänzt.
4. Eine finale Revision erzeugen und erneut anfordern: Überschreiben ist ausgeschlossen; nur „Neue Revision anlegen“ mit Pflichtbegründung ist möglich.
5. Revision 2 erzeugen: Revision 1 bleibt unverändert und sichtbar, ist als ersetzt markiert und verweist auf Revision 2.
6. Fehler bei der Vollmachterzeugung simulieren: Weder Auftrag noch Vollmacht dürfen als vollständiges neues Paket verbleiben.
7. Erzeugungsrequest doppelt absenden: Es entsteht nur eine Paket-/Revisionskennung.
8. Direkte API-Versuche zum Bearbeiten oder Löschen von `FINAL`, `UNTERSCHRIEBEN`, `VERSENDET` und `ERSETZT` müssen abgewiesen und auditiert werden.
9. Desktop- und schmale Ansicht prüfen: Dokumentvorschau bleibt nutzbar, die A4-Seite wird passend skaliert und nicht horizontal abgeschnitten.
10. Druck aus der Vorschau prüfen: Der Ausdruck enthält nur das Dokument und keine Nextcloud-Navigation, Formularelemente oder Assistenten-Schaltfläche.

## Risiken und Abstimmung

- Die Revisionslogik betrifft Dokumentregister, Dateinamen, Audit, Berechtigungen und möglicherweise Aufbewahrungs-/Löschprozesse. Vor der Umsetzung ist festzulegen, ab welchem Status ein Dokument handels- oder beweisrelevant als unveränderlich gilt.
- Für unterschriebene Dokumente ist zu klären, ob eine neue Revision stets eine erneute Unterschrift erfordert. Empfohlen wird: ja; eine neue Revision übernimmt niemals den Unterschriftsstatus des Vorgängers.
- Temporäre Vorschauen können personenbezogene Daten enthalten. Speicherung, Ablaufzeit, Zugriffsschutz und Bereinigung müssen den Regeln der Fallakte entsprechen; bevorzugt wird eine kurzlebige, zugriffsgeschützte Ausgabe ohne dauerhaften Dokumentdatensatz.
- Die aktuelle Löschung und Neuerzeugung von Entwurfsdateien darf erst nach erfolgreicher, vollständig getesteter Migration auf das neue Modell geändert werden. Bestandsdokumente müssen weiterhin geöffnet werden können.

## Nicht Bestandteil

- Qualifizierte elektronische Signaturen oder externe Signaturdienste.
- Versand per E-Mail oder an Behörden.
- Inhaltliche Neugestaltung der bereits vorhandenen Word-Vorlagen außerhalb der für die Vorschau notwendigen Korrekturen.
