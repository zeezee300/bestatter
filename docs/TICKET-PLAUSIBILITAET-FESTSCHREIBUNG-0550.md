# Realisierungsticket: Plausibilitätsprüfung vor Festschreibung

## Ziel und fachliche Entscheidung

Beim Wechsel eines KVA aus `Entwurf` nach `KVA versendet` beziehungsweise eines Auftrags aus `Entwurf` nach `beauftragt` wird eine zentrale Festschreibungsprüfung ausgeführt. Der Statuswechsel ist keine gewöhnliche Stammdatenänderung, sondern erzeugt weiterhin den unveränderlichen kaufmännischen Dokumentstand.

Die Prüfung unterscheidet zwei Stufen:

- `KRITISCH`: Festschreibung ist nicht möglich. Das erste betroffene Feld beziehungsweise die Position wird fokussiert.
- `WARNUNG`: Festschreibung ist möglich, aber erst nach ausdrücklicher gemeinsamer Bestätigung aller angezeigten Hinweise.

## Blockierende Regeln

1. Fallnummer sowie Vor- und Nachname der verstorbenen Person sind vorhanden.
2. Auftraggeber enthält Name, Vorname, Straße, PLZ/Ort und Land.
3. Ein abweichender Rechnungsempfänger enthält dieselben Adressdaten.
4. Dokumentnummer und gültiges Dokumentdatum sind vorhanden; ein Auftrag besitzt zusätzlich eine Art der Beauftragung.
5. Mindestens eine Position ist vorhanden. Jede Position besitzt eine 10er-Positionsnummer, Bezeichnung, positive Menge, Einheit, zulässigen MwSt.-Satz und einen Positionstyp `EL`, `FK` oder `DP`.
6. Eine Freitextposition besitzt einen Nettopreis größer als 0,00 Euro.
7. Bei SEPA-Lastschrift sind Mandatsreferenz, Mandatsdatum, Einzugsdatum, Kontoinhaber und IBAN vorhanden.
8. Bereits festgeschriebene oder fachlich nur über die KVA-Übernahme fortsetzbare Dokumentstände werden zurückgewiesen.

## Bestätigungspflichtige Hinweise

- Katalogposition ohne Preis
- MwSt.-Satz 0 %
- derselbe Katalogartikel in mehreren Positionen
- Positionstyp FK oder DP ohne erläuternde Positionsnotiz
- Gesamtsumme 0,00 Euro
- Auftraggeber ohne Telefon, Mobilnummer und E-Mail
- Auftrag ohne digitale Bestätigung/Unterschrift

## Technische Umsetzung

- `POST /api/cases/{caseId}/commercial/finalization-check` liefert Ergebnis, Fehler, Hinweise und Summen.
- Die Oberfläche speichert Kopf und Positionen, ruft die Vorprüfung auf und zeigt Fehler beziehungsweise Hinweise gesammelt an.
- Die Festschreibungsendpunkte prüfen unmittelbar vor dem Schreiben erneut. Warnungen gelten nur als bestätigt, wenn ihre stabilen Codes übergeben wurden.
- Das vollständige Prüfergebnis einschließlich bestätigter Warncodes, Prüfzeitpunkt und Benutzer wird im Dokument-Snapshot abgelegt.
- Statusauswahl und Aktionsbuttons verwenden dieselbe Funktion. Dadurch kann `beauftragt`/`KVA versendet` nicht als bloßer, weiterhin bearbeitbarer Stammdatenstatus gespeichert werden.

## Testfälle

1. Fehlender Auftraggeber-Nachname blockiert und verweist auf das Feld.
2. Abweichender Rechnungsempfänger ohne Straße blockiert.
3. Leere Positionsliste sowie Freitext ohne Preis blockieren.
4. Ungültiger Positionstyp, Menge 0, fehlende Einheit oder unzulässige MwSt. blockieren.
5. Unvollständiges SEPA-Mandat blockiert; Überweisung benötigt keine Mandatsdaten.
6. Nullpreis, 0 % MwSt., doppelte Artikel und FK/DP ohne Notiz erzeugen Hinweise.
7. Ein Hinweis kann nicht durch direkten Festschreibungsaufruf ohne bestätigten Warncode umgangen werden.
8. Nach Bestätigung wird genau ein Snapshot erzeugt; erneute Festschreibung bleibt gesperrt.
9. Auswahl von `beauftragt` beziehungsweise `KVA versendet` durchläuft denselben Prüfweg wie der Button.
