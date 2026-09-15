# Realisierungsticket: SAP-orientierte Auftrag-/KVA-Positionserfassung

> Offene Korrekturen zu Statuskonsistenz, Sperrlogik, Präfixsuche, Darstellung und Wertelisten sind im Folgeticket `TICKET-KORREKTUR-AUFTRAGSPOSITIONEN-STATUS-AUTOCOMPLETE.md` beschrieben.

## Ziel

Die manuelle Erfassung von Kostenvoranschlägen und Aufträgen wird auf eine schlanke Kopf-/Positionsstruktur umgestellt. Die Positionsliste ist die zentrale Arbeitsfläche; der Leistungskatalog wird ausschließlich als Datenquelle für die Inline-Trefferliste am Material-/Leistungsnummernfeld verwendet.

## Umgesetzt

- Kopfdaten und Positionsdaten bleiben fachlich getrennt.
- Positionen werden mit 10, 20, 30 usw. geführt; gelöschte Nummern werden nicht automatisch wiederverwendet.
- Katalogpositionen werden bereits während der Eingabe der Material-/Leistungsnummer gesucht und als Trefferliste unterhalb der Positionszeile angeboten. Die Auswahl übernimmt Materialnummer, Bezeichnung, Langtext, Mengeneinheit, Preis, Steuersatz und Positionstyp aus dem Leistungskatalog.
- Menge ist mit 1 vorbelegt und änderbar.
- Freitextpositionen ohne Materialnummer sind möglich.
- EL, FK und DP werden als Positionstypen geführt.
- Leistungsstatus, Abrechenbarkeit, Herkunft und Positionstyp bleiben getrennte Sachverhalte.
- Positionen können im Entwurf hinzugefügt, geändert und entfernt werden.
- Der in den Kopfdaten angezeigte Auftragsstatus `Entwurf` ist für die Bearbeitbarkeit maßgeblich. Ein bereits vorhandener Dokumentdatensatz darf die laufende Entwurfserfassung nicht irrtümlich sperren.
- Analog zur SAP-Positionsübersicht steht am Tabellenende immer genau eine neue, leere Erfassungszeile bereit. Ein separater Button „Neue Position“ ist nicht erforderlich.
- Die Tabulator-Reihenfolge beginnt je Position mit der Material-/Leistungsnummer und läuft danach über Bezeichnung, Menge, Einheit, Einzelpreis und Positionstyp. Detail- und Löschaktionen unterbrechen diese schnelle Eingabefolge nicht.
- Die Standardansicht zeigt nur die Kernfelder.
- Weitere Positionsdaten werden über „Details“ je Position erschlossen.
- Es gibt keinen sichtbaren, nachgelagerten Katalog-Auswahlbereich; die Auswahl erfolgt ausschließlich direkt an der jeweiligen Positionszeile.

## Kernfelder der Standardansicht

Position, Materialnummer, Bezeichnung, Menge, Einheit, Einzelpreis netto, Positionstyp und Gesamtbetrag. Die Positionsnummer ist nur Anzeige; die erste Eingabe erfolgt in der Materialnummer.

## Detailfelder und Erweiterungspunkte

Langtext, Steuersatz, steuerliche Klassifikation, Leistungszeitraum, Lieferant, Fremdbeleg, interne Notiz, beauftragte/erbrachte/fakturierte Menge, Nachtrags- und Änderungsinformationen bleiben für eine spätere Detailansicht vorbereitet.

## Positionstypen

- EL: eigene Leistung
- FK: Fremdkosten/Fremdleistung
- DP: echter durchlaufender Posten

DP ist keine automatische steuerliche Entscheidung. Die fachliche Klassifikation und der Nachweis werden in einem späteren Abrechnungs-/Eingangsrechnungsprozess geprüft.

## Technische Leitplanken

- Katalog- und Freitextpositionen werden als Positionssnapshot gespeichert.
- Nachfolgende Katalogänderungen verändern bestehende KVA-/Auftragspositionen nicht.
- Bereits erbrachte oder fakturierte Mengen bleiben durch die vorhandene Lifecycle-/Abrechnungslogik geschützt.
- Nach Festschreibung gelten die bestehenden Vertragsnachtragsregeln.

## Abnahmekriterien

- Eine Position kann per Materialnummer oder Freitext erfasst werden.
- Die Eingabe eines Präfixes wie AU- zeigt sofort alle passenden Artikel-/Leistungsnummern und Bezeichnungen als auswählbare Treffer.
- Nach Auswahl einer Katalogposition stimmen die übernommene Materialnummer, Bezeichnung, Langtext, Einheit, Menge, Preis, Steuersatz und Positionstyp mit dem Katalogsnapshot überein.
- Die Kernfelder sind ohne Detaildialog schnell bearbeitbar.
- Ein Detailbereich ist pro Position erreichbar, ohne die Standardtabelle zu überladen.
- Positionen werden in 10er-Schritten gespeichert und ausgegeben.
- EL/FK/DP werden gespeichert, angezeigt und nicht mit service_status oder billability vermischt.
- Katalogdaten werden übernommen, aber als Snapshot gespeichert.
- Neue, geänderte und entfernte Positionen werden im Entwurf korrekt gespeichert.
- Die Nummerierung wird bei jedem Speichern positionsbezogen auf `10, 20, 30, ...` normalisiert; doppelte oder abweichende Positionsnummern werden nicht fortgeschrieben.
- Nach der letzten belegten Position ist ohne Zusatzaktion eine leere Folgeposition sichtbar. Nach ihrer Erfassung wird automatisch die nächste Leerzeile angeboten.
- KVA, Auftrag, Leistungserfassung und Rechnung verwenden dieselben Positionsdaten und Summen.
- Bestehende Auswahl, Paketlogik, Vertragsfestschreibung und Eingangsrechnungsübernahme bleiben funktionsfähig.

## Bewusst nachgelagerte Ausbaustufen

Die Detailansicht kann später um Lieferanten-/Fremdbelegbezug, Leistungszeitraum, steuerliche Klassifikation, Nachtragsinformationen und weitere Abrechnungsfelder erweitert werden, ohne die Kernmaske zu vergrößern.
