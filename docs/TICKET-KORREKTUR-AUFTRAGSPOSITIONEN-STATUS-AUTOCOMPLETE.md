# Korrekturticket: Auftragsstatus, Positionssperre und Katalogabgleich

## Umsetzungsstand

Die ursprünglichen Korrekturen wurden in Version 0.53.3 begonnen und nach der realen Browser-Abnahme des nicht festgeschriebenen Testfalls `2026-0006` in Version 0.54.0 stabilisiert und erweitert. Maßgeblich für Suche, Persistenz und die Ausbaustufen 1 bis 2 ist das Folgeticket `TICKET-AUFTRAGSPOSITIONEN-0540.md`.

### Ergänzende Bedienkorrekturen 0.53.3

- Einheit und Positionstyp zeigen in der geschlossenen Tabellenzeile nur das Kürzel; beim Fokussieren beziehungsweise Öffnen erscheinen Schlüssel und Bezeichnung.
- Die Herkunft wird als `KAT` für Katalogposition und `FREI` für Freitextposition sichtbar ausgewiesen.
- Ein eingegebener, aber nicht ausgewählter Suchpräfix ist keine Position und erzeugt beim Verlassen des Feldes keine neue Freitext- oder Folgeposition.
- Katalogauswahl übernimmt und zeigt Materialnummer, Kurztext und Sachmerkmale unmittelbar; Maus und Tastatur werden unterstützt.
- Freitextpositionen benötigen eine Bezeichnung, einen Preis größer als null und einen zulässigen Mehrwertsteuersatz. Die Prüfung erfolgt im Browser und auf dem Server.
- Der Zugang zu den Positionsdetails steht direkt neben der Bezeichnung und belegt keine eigene Zeile.

## Ausgangslage

Bei der Prüfung des Falls `2026-0005` wurden ein widersprüchlicher kaufmännischer Status sowie weitere Bedien- und Zuordnungsfehler in der Auftragserfassung festgestellt. Die Oberfläche zeigt den Auftrag als `Entwurf`, meldet gleichzeitig jedoch einen festgeschriebenen Auftrag und eine bereits vorhandene Rechnung. Trotz der daraus folgenden Vertragssperre sind Positionsfelder teilweise weiterhin eingabebereit.

Der vorhandene Fall wird nicht als erneuter Abnahmefall verwendet, weil sein Datenbestand bereits festgeschriebene Dokumente und eine Rechnung enthält. Für die Korrektur ist ein neuer, eindeutig benannter Testfall ohne kaufmännische Vorbelegung anzulegen.

## Ziel

Kopfdaten, Vertragsfestschreibung, Rechnungsstatus und Bearbeitbarkeit der Positionen müssen jederzeit denselben fachlichen Zustand abbilden. Die SAP-orientierte Positionsschnellerfassung erhält außerdem eine präzise Materialnummernsuche, besser lesbare Positionsdaten und einheitliche, kataloggestützte Wertelisten.

## 1. Konsistenter Auftragsstatus und bereinigte Testdaten

### Fehlerbild

Im Fall `2026-0005` stehen gleichzeitig folgende Aussagen in der Oberfläche:

- Auftragsstatus `Entwurf`;
- Auftrag `A-2026-0005` ist festgeschrieben;
- mindestens eine nicht stornierte Rechnung ist vorhanden;
- Vertragsänderungen sind gesperrt.

Diese Kombination ist fachlich unzulässig. Sobald ein Auftrag festgeschrieben oder eine Rechnung angelegt wurde, darf der aktuell wirksame Auftrag nicht mehr als `Entwurf` erscheinen.

### Sollverhalten

- Der kaufmännische Status wird aus einer zentralen, serverseitigen Zustandslogik ermittelt und nicht aus voneinander unabhängigen UI-, Stammdaten- und Dokumentwerten zusammengesetzt.
- Eine Auftragsfestschreibung setzt den wirksamen Auftragsstatus mindestens auf `beauftragt`.
- Eine vorhandene, nicht stornierte Rechnung schließt den Status `Entwurf` aus.
- Das Statusfeld darf keine fachlich unzulässige Rückstufung auf `Entwurf` anbieten.
- Bestehende widersprüchliche Daten werden durch eine wiederholungssichere Reparaturprüfung erkannt. Die Korrektur wird auditiert; Rechnungen und festgeschriebene Dokument-Snapshots werden nicht verändert.
- Der Schutzhinweis nennt den tatsächlich wirksamen Zustand und wird nur angezeigt, wenn die Positionen auch wirklich gesperrt sind.

### Neuer Abnahmefall

Für die Abnahme wird ein neuer Fall mit der nächsten regulär erzeugten Fallnummer angelegt. Er besitzt zunächst:

- Auftragsstatus `Entwurf`;
- keine festgeschriebenen KVA-/Auftragsdokumente;
- keine Rechnung;
- keine vorbelegten Auftragspositionen.

Der Testauftrag bleibt nach der Prüfung im Status `Entwurf` und wird nicht festgeschrieben oder abgeschlossen, damit eine manuelle Nachprüfung möglich bleibt.

## 2. Einheitliche Sperrlogik für alle Positionsfelder

- Im wirksamen Status `Entwurf` sind Materialnummer, Freitextbezeichnung, Menge, Einheit, Preis und Positionstyp sowie das Entfernen einer Position verfügbar.
- Nach Festschreibung sind sämtliche verändernden Positionsfelder, die automatische Leerzeile, Auswahlaktionen und Löschaktionen gemeinsam gesperrt.
- Bei vorhandener Rechnung bleibt die Sperre auch dann bestehen, wenn alte Stammdaten irrtümlich noch `Entwurf` enthalten.
- Schreibschutz darf nicht nur optisch wirken. Der Server weist unzulässige Änderungsversuche unabhängig vom Browserzustand zurück.
- Read-only-Felder erhalten eine verständliche Begründung; es darf keine eingabebereite Darstellung mit anschließendem unerwartetem Speicherfehler geben.

## 3. Präzise Autovervollständigung der Material-/Leistungsnummer

### Suchregel ersetzt in 0.54.0

- Jede nicht leere Eingabe startet die Suche; ein Bindestrich besitzt keine Sonderbedeutung.
- Exakte Artikelnummern und Artikelnummern mit passendem Beginn haben Vorrang vor Wortanfangs- und sonstigen Texttreffern.
- Zusätzlich werden Kurztext, Langtext, Kategorie und Artikelgruppe durchsucht und nach Relevanz eingeordnet.
- Die sichtbare eigene Trefferliste und eine gegebenenfalls verwendete native Browser-Vervollständigung müssen dieselbe gefilterte Datenmenge verwenden. Eine ungefilterte `datalist` ist zu entfernen oder dynamisch zu begrenzen.
- Groß-/Kleinschreibung wird ignoriert; Bindestriche werden nicht bedeutungsverändernd entfernt.

### Beispiele

Nach Eingabe von `AU-` stehen passende `AU-…`-Nummern wegen ihrer höheren Relevanz zuerst. Artikelnummern ohne Bindestrich, beispielsweise `12345`, werden bereits ab dem ersten eingegebenen Zeichen gefunden. Texttreffer bleiben verfügbar, werden aber hinter Nummerntreffern einsortiert.

### Zusätzliche Katalogsuche

- Die bisherige Suche und Auswahl aus der vollständigen Artikelliste beziehungsweise dem Leistungskatalog bleibt erhalten.
- Sie wird als optionaler, ausdrücklich zu öffnender Katalogdialog angeboten und verdrängt weder die Inline-Suche noch die automatische Leerzeile.
- Der Dialog unterstützt weiterhin Volltextsuche, Kostenart, Artikelgruppe, Einzel-/Paketleistung und Pagination.
- Eine Auswahl im Dialog übernimmt dieselben Katalogfelder und verwendet denselben Speicherweg wie die Auswahl direkt am Materialnummernfeld.
- Der vollständige Katalog wird nicht dauerhaft unterhalb der Positionsübersicht eingeblendet.

## 4. Breitere Bezeichnung

- Die Spalte `Bezeichnung` erhält auf üblichen Desktopbreiten deutlich mehr Platz als Menge, Einheit und Positionstyp.
- Zielwert ist eine nutzbare Eingabebreite von mindestens 280 Pixeln; bei mehr Platz wächst die Spalte flexibel.
- Die Materialnummer bleibt vollständig erkennbar.
- Auf schmalen Ansichten darf die Tabelle horizontal scrollen; fachliche Werte dürfen nicht abgeschnitten oder übereinandergelegt werden.

## 5. Positionstypen und pflegbare Werteliste

Die bisherige fachliche Festlegung lautet:

- `EL` – eigene Leistung;
- `FK` – Fremdkosten/Fremdleistung;
- `DP` – echter durchlaufender Posten.

Diese fachliche Festlegung bleibt verbindlich. Abweichende Kürzel wie `DK` oder `SP` sind keine zulässigen Positionstypen und werden als Datenfehler gemeldet; sie werden nicht als Synonyme oder zusätzliche Werte eingeführt.

Es wird eine eigene pflegbare Werteliste `POSITION_TYPE` mit genau diesen drei Ausgangswerten erzeugt. Dabei gelten kontrollierte Grenzen:

- technischer Schlüssel bleibt für Abrechnung, Auswertung und Migration stabil;
- sichtbare Bezeichnung, Beschreibung, Sortierung und Aktivkennzeichen sind administrativ pflegbar;
- neue technische Schlüssel benötigen eine fachliche Freigabe und dürfen nicht allein durch Umbenennen entstehen;
- Standardwert und Zuordnung zur Kostenart werden zentral definiert;
- Auswahlfelder zeigen immer Schlüssel und Bezeichnung, zum Beispiel `EL – eigene Leistung`.

Empfohlener Wertelistenschlüssel: `POSITION_TYPE`.

## 6. Kategorie/Gruppe und Kostenart

- Kategorie beziehungsweise Artikelgruppe und Kostenart werden aus demselben Katalogdatensatz wie Materialnummer, Kurztext, Preis und Einheit übernommen.
- In der Trefferliste erscheinen sie als kompakte Zusatzinformation unter Materialnummer und Kurztext.
- In der Positionsübersicht werden sie auf ausreichend breiten Ansichten als nicht editierbare Zusatzangabe angezeigt; auf schmalen Ansichten stehen sie in den Positionsdetails, damit die Schnellerfassung nicht überladen wird.
- Katalogpositionen speichern diese Werte als Snapshot. Spätere Katalogänderungen verändern bestehende Aufträge nicht.
- Freitextpositionen erhalten eine fachlich passende Auswahl beziehungsweise Ableitung und dürfen keine erfundenen Katalogwerte vortäuschen.

## 7. Gemeinsame Einheitenliste

- Artikelkatalog und Auftragserfassung verwenden dieselbe zentrale Liste zulässiger Mengeneinheiten.
- Angezeigt werden technischer Schlüssel und verständliche Bezeichnung, zum Beispiel `STK – Stück`, `STD – Stunden` oder `KM – Kilometer`.
- Bei Auswahl eines Katalogartikels werden Einheit und zulässige Mengenpräzision übernommen.
- Eine Änderung ist nur auf eine aktive Einheit der gemeinsamen Werteliste möglich; freie, abweichende Schreibweisen sind ausgeschlossen.
- Der gespeicherte Positionssnapshot enthält den stabilen Einheitenschlüssel. Dokumente und Oberfläche verwenden die dazugehörige Bezeichnung.
- Unbekannte Einheiten in Bestandsdaten werden durch die Reparaturprüfung gemeldet und nicht stillschweigend umgedeutet.

Empfohlener Wertelistenschlüssel: `QUANTITY_UNIT`.

## Technische Leitplanken

- Eine zentrale Methode liefert den effektiven kaufmännischen Zustand einschließlich `editable`, `reason` und zugrunde liegender Dokument-/Rechnungsreferenz an Frontend und Schreib-API.
- Frontend-Sperre und serverseitige Validierung verwenden dieselbe Zustandsentscheidung.
- Statusreparatur, Typbereinigung und Einheitenprüfung sind wiederholungssicher und auditierbar.
- Die Präfixsuche wird getrennt von der allgemeinen Textsuche implementiert und getestet.
- Die automatische leere Position wird bei gesperrten Aufträgen nicht als eingabebereite Zeile dargestellt.

## Abnahmetests

1. Ein neu angelegter Testfall im Status `Entwurf` zeigt keinen Vertragsschutz-Hinweis; alle Kernfelder und die automatische Leerzeile sind bearbeitbar.
2. Nach Festschreibung des Testauftrags wechselt der Status auf `beauftragt`; sämtliche Positionsänderungen sind in UI und API gesperrt.
3. Nach Anlage einer Rechnung kann der Auftrag weder angezeigt noch gespeichert auf `Entwurf` zurückgesetzt werden.
4. Ein absichtlich inkonsistenter Testdatensatz wird von der Reparaturprüfung erkannt und nachvollziehbar korrigiert, ohne Dokument- oder Rechnungssnapshot zu verändern.
5. Die Eingabe `AU-` zeigt ausschließlich Artikelnummern, die mit `AU-` beginnen; `LA-URNE-STANDARD` und `DL-LAUTSPRECHER` fehlen.
6. Auswahl eines Katalogartikels übernimmt Materialnummer, Kurztext, Kategorie/Gruppe, Kostenart, Einheit, Mengenpräzision, Preis, Steuer und Positionstyp aus genau einem Katalogsnapshot.
7. Die Bezeichnung ist auf Desktopbreite mindestens 280 Pixel nutzbar; die Tabelle bleibt bei schmaler Darstellung bedienbar.
8. Die neue Werteliste `POSITION_TYPE` enthält `EL – eigene Leistung`, `FK – Fremdkosten/Fremdleistung` und `DP – echter durchlaufender Posten`; Auswahlfelder zeigen Schlüssel und Bezeichnung. Andere Schlüssel wie `DK` oder `SP` werden nicht gespeichert.
9. Einheitenauswahl und Artikelpflege verwenden dieselbe Werteliste und zeigen beispielsweise `STK – Stück` identisch an.
10. Die zusätzliche vollständige Katalogsuche lässt sich öffnen, filtern und zur Positionsübernahme verwenden, ohne einen Katalogbereich dauerhaft unterhalb der Positionen einzublenden.
11. Der neue fachliche Testauftrag bleibt nach dem abschließenden Erfassungstest offen im Status `Entwurf`; es wird keine Rechnung erzeugt.

## Nicht-Ziele

- Keine Änderung oder Löschung bestehender Rechnungen beziehungsweise festgeschriebener Dokument-Snapshots.
- Keine freie administrative Erfindung neuer Positionstyp-Schlüssel ohne Prüfung der Abrechnungsfolgen.
- Kein erneutes Öffnen des vollständigen Leistungskatalogs unterhalb der Positionsübersicht.

## Aufwand und Priorität

- Priorität: hoch, da Statuswiderspruch und abweichende UI-/API-Sperren zu fachlich unzulässigen Änderungen führen können.
- Aufwand: mittel. Betroffen sind Zustandsmodell, Bestandsdatenprüfung, Positions-UI, Autovervollständigung, Wertelistenanbindung sowie automatisierte und manuelle Abnahme.
